<?php

namespace App\Payments\Data;

/**
 * A plan as the provider reports it.
 */
final readonly class GatewayPlan
{
    public function __construct(
        public string $code,
        public int $amount,
        public string $currency,
        public string $interval,
        public bool $active,
    ) {}
}
