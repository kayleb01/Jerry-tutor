<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentGatewayManager;
use App\Payments\PaystackGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class UpdateCardController extends Controller
{
    /**
     * Paystack hosts a page for changing the card; Flutterwave has none, so the payer is told what to do.
     */
    public function __invoke(Request $request, Subscription $subscription, PaymentGatewayManager $gateways): Response|RedirectResponse
    {
        abort_unless($subscription->payer_id === $request->user()->id, 403);

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) || $subscription->cancel_at_period_end) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "This subscription isn't renewing, so there's no card to update."]);

            return back();
        }

        $gateway = $gateways->for($subscription);

        if (! $gateway instanceof PaystackGateway) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Flutterwave doesn’t let you swap the card on a subscription. Cancel it here, then subscribe again with the new card.']);

            return back();
        }

        try {
            return Inertia::location($gateway->manageLink($subscription));
        } catch (GatewayException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }
    }
}
