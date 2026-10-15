<?php

namespace App\Payments;

use App\Enums\PaymentGatewayName;
use App\Models\Subscription;
use App\Payments\Contracts\PaymentGateway;
use Illuminate\Support\Manager;

/**
 * Resolves payment providers: driver() for new checkouts, for() for an existing subscription.
 *
 * @method PaymentGateway driver(string|null $driver = null)
 */
class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('payments.default');
    }

    public function defaultGateway(): PaymentGatewayName
    {
        return PaymentGatewayName::from($this->getDefaultDriver());
    }

    /**
     * A subscription always goes back to the provider that created it.
     */
    public function for(Subscription $subscription): PaymentGateway
    {
        return $this->driver($subscription->gateway->value);
    }

    public function gateway(PaymentGatewayName $name): PaymentGateway
    {
        return $this->driver($name->value);
    }

    protected function createPaystackDriver(): PaystackGateway
    {
        return new PaystackGateway(
            (string) $this->config->get('payments.paystack.secret_key'),
            (string) $this->config->get('payments.paystack.base_url'),
        );
    }

    protected function createFlutterwaveDriver(): FlutterwaveGateway
    {
        return new FlutterwaveGateway(
            (string) $this->config->get('payments.flutterwave.secret_key'),
            (string) $this->config->get('payments.flutterwave.secret_hash'),
            (string) $this->config->get('payments.flutterwave.base_url'),
        );
    }
}
