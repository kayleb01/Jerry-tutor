<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CancelSubscriptionController extends Controller
{
    /**
     * Stop renewals at the subscription's own gateway. Access continues until the paid period ends.
     */
    public function __invoke(Request $request, Subscription $subscription, PaymentGatewayManager $gateways): RedirectResponse
    {
        abort_unless($subscription->payer_id === $request->user()->id, 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) || $subscription->cancel_at_period_end) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "This subscription isn't renewing, so there's nothing to cancel."]);

            return back();
        }

        try {
            $gateways->for($subscription)->cancel($subscription);
        } catch (GatewayException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        $subscription->update([
            'cancel_at_period_end' => true,
            'cancel_requested_at' => now(),
            'cancellation_reason' => filled($validated['reason'] ?? null) ? trim($validated['reason']) : null,
        ]);

        $until = $subscription->current_period_ends_at?->setTimezone(config('academy.timezone'))->format('j F');
        Inertia::flash('toast', ['type' => 'success', 'message' => $until ? "Cancelled. Access continues until {$until}." : 'Cancelled.']);

        return back();
    }
}
