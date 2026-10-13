<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One person's attendance over the last 30 days. Excused lessons don't count either way.
 */
class AttendanceStats
{
    /**
     * @return array{attendance: int|null, onTime: int|null, late: int, absent: int}
     */
    public function forUser(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $statuses = AttendanceRecord::query()
            ->where('user_id', $user->id)
            ->whereHas('lesson', fn (Builder $query) => $query->whereBetween('starts_at', [$now->subDays(30)->utc(), $now->utc()]))
            ->pluck('status');

        $count = fn (AttendanceStatus $wanted): int => $statuses->filter(fn (AttendanceStatus $status): bool => $status === $wanted)->count();
        $present = $count(AttendanceStatus::Present);
        $late = $count(AttendanceStatus::Late);
        $absent = $count(AttendanceStatus::Absent);
        $attended = $present + $late;

        return [
            'attendance' => $attended + $absent === 0 ? null : (int) round($attended / ($attended + $absent) * 100),
            'onTime' => $attended === 0 ? null : (int) round($present / $attended * 100),
            'late' => $late,
            'absent' => $absent,
        ];
    }
}
