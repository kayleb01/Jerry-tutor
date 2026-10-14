<?php

namespace App\Enums;

enum KeyStage: string
{
    case Ks2 = 'ks2';
    case Ks3 = 'ks3';
    case Ks4 = 'ks4';

    public function label(): string
    {
        return match ($this) {
            self::Ks2 => 'KS2',
            self::Ks3 => 'KS3',
            self::Ks4 => 'GCSE',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
