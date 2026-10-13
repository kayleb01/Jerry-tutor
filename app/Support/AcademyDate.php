<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Dates typed into filters (Y-m-d) mean days in the academy timezone.
 */
final class AcademyDate
{
    /**
     * Midnight of the given academy date, or null when the value isn't a valid Y-m-d date.
     */
    public static function fromQuery(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('academy.timezone'));

        return $date === false || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
