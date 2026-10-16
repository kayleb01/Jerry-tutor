<?php

namespace App\Support;

use App\Enums\KeyStage;
use Carbon\CarbonImmutable;

/**
 * The attendance page's filters: the admin dashboard's period, class and audience, plus a key stage.
 */
final readonly class AttendanceFilters
{
    public function __construct(
        public AdminDashboardFilters $period,
        public ?KeyStage $stage,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query, ?CarbonImmutable $now = null): self
    {
        return new self(
            AdminDashboardFilters::fromQuery($query, $now),
            is_string($query['stage'] ?? null) ? KeyStage::tryFrom($query['stage']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        return [...$this->period->toProps(), 'stage' => $this->stage?->value];
    }
}
