<?php

namespace App\Payments\Contracts;

use App\Enums\PaymentGatewayName;
use App\Models\Payment;
use App\Models\Subscription;
use App\Payments\Data\GatewayEvent;
use App\Payments\Data\GatewayPlan;
use App\Payments\Data\VerifiedPayment;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\InvalidWebhookSignature;
use Illuminate\Http\Request;

/**
 * One payment provider. All amounts crossing this boundary are integer kobo in NGN;
 * drivers convert to the provider's own units at their edge.
 */
interface PaymentGateway
{
    public function name(): PaymentGatewayName;

    /**
     * Look up a plan created in the provider's dashboard.
     *
     * @throws GatewayException
     */
    public function fetchPlan(string $code): GatewayPlan;

    /**
     * Start a hosted checkout for the subscription's first payment and return the URL to send the payer to.
     *
     * @throws GatewayException
     */
    public function startCheckout(Subscription $subscription, Payment $payment, string $callbackUrl): string;

    /**
     * Ask the provider what really happened to a payment. Never trust the redirect alone.
     *
     * @throws GatewayException
     */
    public function verify(string $reference): VerifiedPayment;

    /**
     * Stop future renewals at the provider.
     *
     * @throws GatewayException
     */
    public function cancel(Subscription $subscription): void;

    /**
     * Check the webhook's signature and turn it into an event, or null when it's one we don't act on.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): ?GatewayEvent;
}
