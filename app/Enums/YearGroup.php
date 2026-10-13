<?php

namespace App\Enums;

enum YearGroup: int
{
    case Year3 = 3;
    case Year4 = 4;
    case Year5 = 5;
    case Year6 = 6;
    case Year7 = 7;
    case Year8 = 8;
    case Year9 = 9;
    case Year10 = 10;
    case Year11 = 11;

    public function keyStage(): KeyStage
    {
        return match ($this) {
            self::Year3, self::Year4, self::Year5, self::Year6 => KeyStage::Ks2,
            self::Year7, self::Year8, self::Year9 => KeyStage::Ks3,
            self::Year10, self::Year11 => KeyStage::Ks4,
        };
    }

    /**
     * Default quiz difficulty per the design brief. GCSE years use exam tracks instead.
     */
    public function defaultDifficulty(): ?Difficulty
    {
        return match ($this) {
            self::Year3, self::Year4, self::Year7 => Difficulty::Easy,
            self::Year5, self::Year8 => Difficulty::Intermediate,
            self::Year6, self::Year9 => Difficulty::Hard,
            self::Year10, self::Year11 => null,
        };
    }

    public function label(): string
    {
        return "Year {$this->value}";
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $yearGroup): array => ['value' => $yearGroup->value, 'label' => $yearGroup->label()],
            self::cases(),
        );
    }
}
