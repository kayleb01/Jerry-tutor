<?php

namespace App\Enums;

enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public function label(): string
    {
        return $this->name;
    }

    public function plural(): string
    {
        return $this->name.'s';
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $weekday): array => ['value' => $weekday->value, 'label' => $weekday->label()],
            self::cases(),
        );
    }
}
