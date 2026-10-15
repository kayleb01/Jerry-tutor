<?php

namespace App\Actions;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Payments\Data\GatewayEvent;
use App\Payments\Data\GatewayEventType;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\UnmatchedGatewayEvent;
use App\Payments\FlutterwaveGateway;
use App\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only writer of payment and subscription state from a gateway, for the callback and for webhooks.
 * Every step is keyed on the payment reference, so applying the same event twice changes nothing.
 */
class ApplyGatewayEvent
{
    public function __construct(private PaymentGatewayManager $gateways) {}

    /**
     * @throws UnmatchedGatewayEvent when no subscription matches (the webhook job retries later)
     */
    public function handle(PaymentGatewayName $gateway, GatewayEvent $event): void
    {
        $subscription = DB::transaction(fn (): ?Subscription => match ($event->type) {
            GatewayEventType::PaymentSucceeded => $this->paymentSucceeded($gateway, $event),
            GatewayEventType::PaymentFailed => $this->paymentFailed($gateway, $event),
            GatewayEventType::SubscriptionCreated => $this->subscriptionCreated($gateway, $event),
            GatewayEventType::SubscriptionNotRenewing => $this->update($gateway, $event, ['cancel_at_period_end' => true]),
            GatewayEventType::SubscriptionCancelled => $this->update($gateway, $event, ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now()]),
        });

        if ($subscription && $event->type === GatewayEventType::PaymentSucceeded) {
            $this->linkFlutterwaveSubscription($subscription);
        }
    }

    private function paymentSucceeded(PaymentGatewayName $gateway, GatewayEvent $event): ?Subscription
    {
        $payment = $this->ourPayment($gateway, $event->reference);

        if ($payment) {
            if ($payment->status === PaymentStatus::Succeeded) {
                return null;
            }

            $subscription = Subscription::query()->lockForUpdate()->find($payment->subscription_id);

            if ($event->amount !== $payment->amount || $event->currency !== 'NGN') {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => 'Amount or currency did not match the plan',
                    'gateway_response' => $event->raw,
                ]);

                return null;
            }

            $this->markPaid($payment, $event);

            return $subscription ? $this->activate($subscription, $event) : null;
        }

        // A renewal: the gateway chose the reference, so find the subscription another way.
        $subscription = $this->findSubscription($gateway, $event);

        if ($event->currency !== 'NGN') {
            throw new UnmatchedGatewayEvent("Ignored a {$event->currency} renewal: subscriptions are in naira.");
        }

        $payment = Payment::query()->firstOrCreate(
            ['reference' => $event->reference ?? "{$gateway->value}-{$event->eventKey}"],
            [
                'subscription_id' => $subscription->id,
                'payer_id' => $subscription->payer_id,
                'gateway' => $gateway,
                'amount' => $event->amount ?? $subscription->plan->amount,
                'currency' => 'NGN',
                'status' => PaymentStatus::Pending,
            ],
        );

        if ($payment->status === PaymentStatus::Succeeded) {
            return null;
        }

        $this->markPaid($payment, $event);

        return $this->activate($subscription, $event);
    }

    private function paymentFailed(PaymentGatewayName $gateway, GatewayEvent $event): ?Subscription
    {
        $payment = $this->ourPayment($gateway, $event->reference);

        if ($payment) {
            if ($payment->status === PaymentStatus::Pending) {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => $event->failureReason,
                    'card_brand' => $event->cardBrand,
                    'card_last4' => $event->cardLast4,
                    'gateway_response' => $event->raw,
                ]);
            }

            return null;
        }

        $subscription = $this->findSubscription($gateway, $event);

        Payment::query()->firstOrCreate(
            ['reference' => $event->reference ?? "{$gateway->value}-failed-{$event->eventKey}"],
            [
                'subscription_id' => $subscription->id,
                'payer_id' => $subscription->payer_id,
                'gateway' => $gateway,
                'gateway_transaction_id' => $event->transactionId,
                'amount' => $event->amount ?: $subscription->plan->amount,
                'currency' => $event->currency ?: 'NGN',
                'status' => PaymentStatus::Failed,
                'failure_reason' => $event->failureReason,
                'card_brand' => $event->cardBrand ?? $subscription->card_brand,
                'card_last4' => $event->cardLast4 ?? $subscription->card_last4,
                'gateway_response' => $event->raw,
            ],
        );

        if ($subscription->status === SubscriptionStatus::Active) {
            $subscription->update(['status' => SubscriptionStatus::PastDue]);
        }

        return null;
    }

    private function subscriptionCreated(PaymentGatewayName $gateway, GatewayEvent $event): ?Subscription
    {
        $subscription = $this->findSubscription($gateway, $event);

        $subscription->fill([
            'gateway_subscription_code' => $event->subscriptionCode ?? $subscription->gateway_subscription_code,
            'gateway_email_token' => $event->emailToken ?? $subscription->gateway_email_token,
            'gateway_customer_code' => $event->customerCode ?? $subscription->gateway_customer_code,
        ]);

        if ($event->periodEndsAt && $subscription->status !== SubscriptionStatus::Pending) {
            $subscription->current_period_ends_at = $event->periodEndsAt;
        }

        $subscription->save();

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(PaymentGatewayName $gateway, GatewayEvent $event, array $attributes): ?Subscription
    {
        $subscription = $this->findSubscription($gateway, $event);

        if ($subscription->status !== SubscriptionStatus::Expired) {
            $subscription->update($attributes);
        }

        return null;
    }

    private function ourPayment(PaymentGatewayName $gateway, ?string $reference): ?Payment
    {
        if ($reference === null) {
            return null;
        }

        return Payment::query()->where('gateway', $gateway)->where('reference', $reference)->lockForUpdate()->first();
    }

    private function markPaid(Payment $payment, GatewayEvent $event): void
    {
        $payment->update([
            'status' => PaymentStatus::Succeeded,
            'failure_reason' => null,
            'paid_at' => $event->paidAt ?? CarbonImmutable::now(),
            'gateway_transaction_id' => $event->transactionId ?? $payment->gateway_transaction_id,
            'card_brand' => $event->cardBrand ?? $payment->card_brand,
            'card_last4' => $event->cardLast4 ?? $payment->card_last4,
            'gateway_response' => $event->raw,
        ]);
    }

    /**
     * A verified payment always wins: the subscription runs, even if it had been superseded or lapsed.
     */
    private function activate(Subscription $subscription, GatewayEvent $event): Subscription
    {
        $now = CarbonImmutable::now();
        $from = $subscription->current_period_ends_at?->greaterThan($now) ? $subscription->current_period_ends_at : $now;

        $subscription->fill([
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => null,
            'gateway_customer_code' => $event->customerCode ?? $subscription->gateway_customer_code,
            'gateway_subscription_code' => $event->subscriptionCode ?? $subscription->gateway_subscription_code,
            'gateway_authorization' => $event->authorization ?? $subscription->gateway_authorization,
            'card_brand' => $event->cardBrand ?? $subscription->card_brand,
            'card_last4' => $event->cardLast4 ?? $subscription->card_last4,
            'current_period_ends_at' => $event->periodEndsAt ?? $from->addMonthNoOverflow(),
        ])->save();

        return $subscription;
    }

    /**
     * By the gateway's subscription code, or else the customer's most recent subscription on this gateway.
     *
     * @throws UnmatchedGatewayEvent
     */
    private function findSubscription(PaymentGatewayName $gateway, GatewayEvent $event): Subscription
    {
        $query = Subscription::query()->with('plan')->where('gateway', $gateway)->lockForUpdate();

        $subscription = match (true) {
            filled($event->subscriptionCode) => (clone $query)->where('gateway_subscription_code', $event->subscriptionCode)->first()
                ?? ($event->customerCode ? $this->latestForCustomer(clone $query, $event->customerCode) : null),
            filled($event->customerCode) => $this->latestForCustomer($query, $event->customerCode),
            default => null,
        };

        return $subscription ?? throw new UnmatchedGatewayEvent("No {$gateway->label()} subscription matches this event yet.");
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    private function latestForCustomer(Builder $query, string $customerCode): ?Subscription
    {
        return $query
            ->where('gateway_customer_code', $customerCode)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Pending, SubscriptionStatus::Cancelled])
            ->latest('id')
            ->first();
    }

    /**
     * Flutterwave creates the subscription itself after the first payment; we need its id to cancel later.
     */
    private function linkFlutterwaveSubscription(Subscription $subscription): void
    {
        if ($subscription->gateway !== PaymentGatewayName::Flutterwave || filled($subscription->gateway_subscription_code)) {
            return;
        }

        $driver = $this->gateways->for($subscription);

        if (! $driver instanceof FlutterwaveGateway) {
            return;
        }

        try {
            $id = $driver->findSubscriptionId($subscription->payer->email, (string) $subscription->plan->flutterwave_plan_id);
        } catch (GatewayException $exception) {
            Log::warning('Could not look up the Flutterwave subscription id.', ['subscription' => $subscription->id, 'error' => $exception->getMessage()]);

            return;
        }

        if ($id !== null) {
            $subscription->update(['gateway_subscription_code' => $id]);
        }
    }
}
