<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ApplyGatewayEvent;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Mail\PaymentFailedMail;
use App\Models\GuardianNotice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Payments\Data\GatewayEvent;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentGatewayManager;
use App\Services\SubscriptionsOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The admin's "Needs attention" actions for subscriptions whose payment failed.
 */
class SubscriptionActionsController extends Controller
{
    /**
     * Email the payer about the failed payment and leave a note on their dashboard. At most once a day.
     */
    public function remind(Request $request, Subscription $subscription): RedirectResponse
    {
        $subscription->load(['payer', 'plan', 'students']);
        $payer = $subscription->payer;
        $cooldownEnded = now()->subHours(SubscriptionsOverview::REMINDER_COOLDOWN_HOURS);

        $problem = match (true) {
            $subscription->status !== SubscriptionStatus::PastDue => "This subscription isn't behind on payments.",
            blank($payer->email) => "{$payer->name} has no email address on file.",
            $subscription->payment_reminder_sent_at?->greaterThan($cooldownEnded) ?? false => "{$payer->name} was emailed in the last day. Give them a little time.",
            default => null,
        };

        if ($problem) {
            return $this->toast('error', $problem);
        }

        Mail::to($payer->email)->send(new PaymentFailedMail($subscription));

        $child = $subscription->students->sortBy('name')->first();

        if ($payer->hasRole(Role::Guardian) && $child) {
            GuardianNotice::create([
                'student_id' => $child->id,
                'sent_by_id' => $request->user()->id,
                'note' => "We couldn't take this month's payment for your {$subscription->plan->name}. Please update your card on the Billing page to keep quiz access.",
            ]);
        }

        $subscription->update(['payment_reminder_sent_at' => now()]);

        return $this->toast('success', "Emailed {$payer->name}.");
    }

    /**
     * Stop quiz access now. A later successful payment switches it back on.
     */
    public function pause(Subscription $subscription): RedirectResponse
    {
        if (! in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Active], true)) {
            return $this->toast('error', 'This subscription isn’t giving access, so there’s nothing to pause.');
        }

        $subscription->update(['status' => SubscriptionStatus::Expired]);

        return $this->toast('success', 'Access paused for '.$this->names($subscription).'.');
    }

    /**
     * Charge the saved card again through the subscription's own gateway.
     */
    public function retry(Subscription $subscription, PaymentGatewayManager $gateways, ApplyGatewayEvent $applyGatewayEvent): RedirectResponse
    {
        $subscription->load(['payer', 'plan', 'students']);

        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return $this->toast('error', 'Only a subscription with a failed payment can be retried.');
        }

        if (blank($subscription->gateway_authorization)) {
            return $this->toast('error', 'There’s no saved card to charge. Ask the parent to update their card.');
        }

        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'payer_id' => $subscription->payer_id,
            'gateway' => $subscription->gateway,
            'reference' => 'JGA-'.Str::ulid(),
            'amount' => $subscription->plan->amount,
            'currency' => $subscription->plan->currency,
            'status' => PaymentStatus::Pending,
        ]);

        try {
            $result = $gateways->for($subscription)->chargeSavedCard($subscription, $payment);
        } catch (GatewayException $exception) {
            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => $exception->getMessage()]);

            return $this->toast('error', $exception->getMessage());
        }

        if ($result->pending) {
            return $this->toast('success', 'The payment is still processing. It will show here once it clears.');
        }

        $applyGatewayEvent->handle($subscription->gateway, GatewayEvent::fromVerifiedPayment($result));
        $payment->refresh();

        if ($payment->status === PaymentStatus::Succeeded) {
            $names = $this->names($subscription);
            $verb = $subscription->students->count() === 1 ? 'has' : 'have';

            return $this->toast('success', "Payment taken. {$names} {$verb} access again.");
        }

        return $this->toast('error', 'Card declined again: '.($payment->failure_reason ?? 'no reason given'));
    }

    private function names(Subscription $subscription): string
    {
        return $subscription->loadMissing('students')->students->sortBy('name')->pluck('name')->join(', ', ' and ') ?: 'this family';
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
