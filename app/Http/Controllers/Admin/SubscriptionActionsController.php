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
use App\Payments\Exceptions\GatewayUnreachable;
use App\Payments\PaymentGatewayManager;
use App\Services\SubscriptionsOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            blank($subscription->billingEmail()) => "{$payer->name} has no email address on file.",
            $subscription->payment_reminder_sent_at?->greaterThan($cooldownEnded) ?? false => "{$payer->name} was emailed in the last day. Give them a little time.",
            default => null,
        };

        if ($problem) {
            return $this->toast('error', $problem);
        }

        // Claim the reminder atomically so two clicks can't both send.
        $claimed = Subscription::query()
            ->whereKey($subscription->id)
            ->where(fn ($query) => $query->whereNull('payment_reminder_sent_at')->orWhere('payment_reminder_sent_at', '<=', $cooldownEnded))
            ->update(['payment_reminder_sent_at' => now()]);

        if ($claimed === 0) {
            return $this->toast('error', "{$payer->name} was emailed in the last day. Give them a little time.");
        }

        Mail::to($subscription->billingEmail())->send(new PaymentFailedMail($subscription));

        $child = $subscription->students->sortBy('name')->first();

        if ($payer->hasRole(Role::Guardian) && $child) {
            GuardianNotice::create([
                'student_id' => $child->id,
                'sent_by_id' => $request->user()->id,
                'note' => "We couldn't take this month's payment for your {$subscription->plan->name}. Please update your card on the Billing page to keep quiz access.",
            ]);
        }

        return $this->toast('success', "Emailed {$payer->name}.");
    }

    /**
     * Stop quiz access now. A later successful payment switches it back on.
     */
    public function pause(Subscription $subscription): RedirectResponse
    {
        if ($subscription->status === SubscriptionStatus::Active) {
            return $this->toast('error', 'This payment has since gone through, so access stays on.');
        }

        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return $this->toast('error', 'This subscription isn’t giving access, so there’s nothing to pause.');
        }

        $subscription->update(['status' => SubscriptionStatus::Expired]);

        return $this->toast('success', 'Access paused for '.$this->names($subscription).'.');
    }

    /**
     * Charge the saved card again through the subscription's own gateway. Only one retry runs at a time,
     * and none while an earlier payment is still processing, so a card is never charged twice by accident.
     */
    public function retry(Subscription $subscription, PaymentGatewayManager $gateways, ApplyGatewayEvent $applyGatewayEvent): RedirectResponse
    {
        $lock = Cache::lock("subscription-retry:{$subscription->id}", 60);

        if (! $lock->get()) {
            return $this->toast('error', 'This subscription is already being retried. Give it a moment.');
        }

        try {
            return $this->charge($subscription->fresh(['payer', 'plan', 'students']), $gateways, $applyGatewayEvent);
        } finally {
            $lock->release();
        }
    }

    private function charge(Subscription $subscription, PaymentGatewayManager $gateways, ApplyGatewayEvent $applyGatewayEvent): RedirectResponse
    {
        if ($subscription->status !== SubscriptionStatus::PastDue) {
            return $this->toast('error', 'Only a subscription with a failed payment can be retried.');
        }

        if (blank($subscription->gateway_authorization)) {
            return $this->toast('error', 'There’s no saved card to charge. Ask the parent to update their card.');
        }

        if (SubscriptionsOverview::hasPaymentInFlight($subscription)) {
            return $this->toast('error', 'A payment for this subscription is still processing. Wait for it to clear before trying again.');
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
        } catch (GatewayUnreachable $exception) {
            // The charge may still have gone through; leave it pending for the webhook to settle.
            return $this->toast('error', $exception->getMessage().' If the card was charged, it will show here shortly.');
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
