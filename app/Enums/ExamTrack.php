<?php

namespace App\Enums;

enum ExamTrack: string
{
    case ElevenPlus = 'eleven_plus';
    case Sats = 'sats';
    case Gcse = 'gcse';

    public function label(): string
    {
        return match ($this) {
            self::ElevenPlus => '11+',
            self::Sats => 'Year 6 SATs',
            self::Gcse => 'GCSE',
        };
    }
}
