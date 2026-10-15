<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /**
     * Statuses that still hold a child's place: a child can be on at most one of these.
     *
     * @return array<int, self>
     */
    public static function live(): array
    {
        return [self::Pending, self::Active, self::PastDue];
    }
}
