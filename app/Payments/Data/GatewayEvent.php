<?php

namespace App\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * A provider event in one shape, whichever provider sent it. Amounts are kobo.
 */
final readonly class GatewayEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public GatewayEventType $type,
        public string $eventKey,
        public ?string $reference = null,
        public ?string $subscriptionCode = null,
        public ?string $planCode = null,
        public ?string $customerCode = null,
        public ?string $emailToken = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?CarbonImmutable $periodEndsAt = null,
        public ?string $transactionId = null,
        public ?string $authorization = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    /**
     * The event a server-side verification stands for, so the callback and webhooks share one code path.
     */
    /**
     * The same event with fields replaced, e.g. after re-checking it with the gateway.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public static function fromVerifiedPayment(VerifiedPayment $payment): self
    {
        return new self(
            type: $payment->succeeded ? GatewayEventType::PaymentSucceeded : GatewayEventType::PaymentFailed,
            eventKey: 'callback:'.$payment->reference,
            reference: $payment->reference,
            customerCode: $payment->customerCode,
            amount: $payment->amount,
            currency: $payment->currency,
            transactionId: $payment->transactionId,
            authorization: $payment->authorization,
            cardBrand: $payment->cardBrand,
            cardLast4: $payment->cardLast4,
            paidAt: $payment->paidAt,
            failureReason: $payment->failureReason,
            raw: $payment->raw,
        );
    }
}
