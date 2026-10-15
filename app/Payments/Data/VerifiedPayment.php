<?php

namespace App\Payments\Data;

use Carbon\CarbonImmutable;

/**
 * A payment as the provider confirmed it on a server-to-server check.
 */
final readonly class VerifiedPayment
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $reference,
        public bool $succeeded,
        public int $amount,
        public string $currency,
        public ?string $transactionId = null,
        public ?string $customerCode = null,
        public ?string $authorization = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
