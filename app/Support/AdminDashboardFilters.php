<?php

namespace App\Support;

use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;

/**
 * The admin dashboard's filters: a period (a preset or custom dates) plus an
 * optional class, tutor and audience. Dates are academy-local days; anything
 * unusable falls back to "this week" with a message for the page.
 */
final readonly class AdminDashboardFilters
{
    public const DEFAULT_PERIOD = 'week';

    /**
     * @var array<string, string>
     */
    public const PERIODS = [
        'today' => 'Today',
        'week' => 'This week',
        'last_7' => 'Last 7 days',
        'month' => 'This month',
        'last_30' => 'Last 30 days',
        'last_90' => 'Last 90 days',
        'custom' => 'Custom dates',
    ];

    private const MAX_DAYS = 366;

    public function __construct(
        public string $period,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $classId,
        public ?int $tutorId,
        public ?AttendeeRole $role,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(config('academy.timezone'))->startOfDay();
        $period = is_string($query['period'] ?? null) && array_key_exists($query['period'], self::PERIODS)
            ? $query['period']
            : self::DEFAULT_PERIOD;
        $error = null;

        if ($period === 'custom') {
            [$from, $to, $error] = self::customRange($query);

            if ($error !== null) {
                $period = self::DEFAULT_PERIOD;
            }
        }

        if ($period !== 'custom') {
            $from = match ($period) {
                'today' => $today,
                'last_7' => $today->subDays(6),
                'month' => $today->startOfMonth(),
                'last_30' => $today->subDays(29),
                'last_90' => $today->subDays(89),
                default => $today->startOfWeek(CarbonImmutable::MONDAY),
            };
            $to = $today;
        }

        return new self(
            period: $period,
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            classId: self::positiveInt($query['class'] ?? null),
            tutorId: self::positiveInt($query['tutor'] ?? null),
            role: AttendeeRole::tryFrom(is_string($query['role'] ?? null) ? $query['role'] : ''),
            error: $error,
        );
    }

    /**
     * The days covered, counting both ends.
     */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * The period to compare against: the same point last week or last month for those presets,
     * otherwise the same number of days immediately before.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previous(): array
    {
        return match ($this->period) {
            'week' => [$this->from->subWeek(), $this->to->subWeek()],
            'month' => [$this->from->subMonthNoOverflow(), $this->to->subMonthNoOverflow()],
            default => [$this->from->subDays($this->days()), $this->from->subDay()->endOfDay()],
        };
    }

    public function label(): string
    {
        return $this->period === 'custom' ? $this->range() : self::PERIODS[$this->period];
    }

    /**
     * The dates covered, e.g. "13 – 15 Oct 2025".
     */
    public function range(): string
    {
        return match (true) {
            $this->from->isSameDay($this->to) => $this->to->format('j M Y'),
            $this->from->isSameMonth($this->to) => $this->from->format('j').' – '.$this->to->format('j M Y'),
            $this->from->isSameYear($this->to) => $this->from->format('j M').' – '.$this->to->format('j M Y'),
            default => $this->from->format('j M Y').' – '.$this->to->format('j M Y'),
        };
    }

    /**
     * @return array{period: string, from: string, to: string, class: int|null, tutor: int|null, role: string|null, label: string, range: string, error: string|null}
     */
    public function toProps(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'class' => $this->classId,
            'tutor' => $this->tutorId,
            'role' => $this->role?->value,
            'label' => $this->label(),
            'range' => $this->range(),
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null, 2: string|null}
     */
    private static function customRange(array $query): array
    {
        $from = AcademyDate::fromQuery($query['from'] ?? null);
        $to = AcademyDate::fromQuery($query['to'] ?? null);

        $error = match (true) {
            $from === null || $to === null => 'Choose both a start and an end date.',
            $from->greaterThan($to) => 'The start date must be on or before the end date.',
            $from->diffInDays($to) + 1 > self::MAX_DAYS => 'Choose a range of one year or less.',
            default => null,
        };

        return [$from, $to, $error];
    }

    private static function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }
}
