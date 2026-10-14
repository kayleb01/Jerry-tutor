<?php

namespace App\Enums;

enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case MultiSelect = 'multi_select';
    case Numeric = 'numeric';
    case ShortText = 'short_text';

    public function label(): string
    {
        return match ($this) {
            self::MultipleChoice => 'Multiple choice',
            self::MultiSelect => 'Choose all that apply',
            self::Numeric => 'Number answer',
            self::ShortText => 'Short answer',
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::MultipleChoice || $this === self::MultiSelect;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
