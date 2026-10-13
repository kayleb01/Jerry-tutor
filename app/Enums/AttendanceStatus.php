<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excused = 'excused';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'On time',
            self::Late => 'Late',
            self::Absent => 'Absent',
            self::Excused => 'Excused',
        };
    }
}
