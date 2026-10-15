<?php

namespace App\Payments;

use App\Enums\PaymentGatewayName;
use App\Models\Payment;
use App\Models\Subscription;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\GatewayEvent;
use App\Payments\Data\GatewayPlan;
use App\Payments\Data\VerifiedPayment;
use App\Payments\Exceptions\GatewayException;
use Illuminate\Http\Request;

class FlutterwaveGateway implements PaymentGateway
{
    public function __construct(private string $secretKey, private string $secretHash, private string $baseUrl) {}

    public function name(): PaymentGatewayName
    {
        return PaymentGatewayName::Flutterwave;
    }

    public function fetchPlan(string $code): GatewayPlan
    {
        throw new GatewayException('Not implemented yet.');
    }

    public function startCheckout(Subscription $subscription, Payment $payment, string $callbackUrl): string
    {
        throw new GatewayException('Not implemented yet.');
    }

    public function verify(string $reference): VerifiedPayment
    {
        throw new GatewayException('Not implemented yet.');
    }

    public function cancel(Subscription $subscription): void
    {
        throw new GatewayException('Not implemented yet.');
    }

    public function parseWebhook(Request $request): ?GatewayEvent
    {
        throw new GatewayException('Not implemented yet.');
    }
}
