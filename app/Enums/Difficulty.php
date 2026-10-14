<?php

namespace App\Enums;

enum Difficulty: string
{
    case Easy = 'easy';
    case Intermediate = 'intermediate';
    case Hard = 'hard';
    case Challenging = 'challenging';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
