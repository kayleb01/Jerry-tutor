<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lessons that have ended in the last 48 hours which this person may still report on
 * and hasn't yet — so the reporting window is reachable after the lesson leaves "today".
 */
class ReportsDue
{
    /**
     * @return array<int, array{id: int, className: string, when: string, closesAt: string}>
     */
    public function for(User $user): array
    {
        $now = CarbonImmutable::now();

        return Lesson::query()
            ->where('status', '!=', LessonStatus::Cancelled)
            ->where('ends_at', '<=', $now)
            ->where('ends_at', '>=', $now->subHours(48))
            ->whereDoesntHave('lessonReports', fn (Builder $query) => $query->where('author_id', $user->id))
            ->where(fn (Builder $query) => $user->hasRole(Role::Student)
                ? $query->whereHas('attendanceRecords', fn (Builder $records) => $records
                    ->where('user_id', $user->id)
                    ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late]))
                : $query->where('tutor_id', $user->id))
            ->with('classGroup')
            ->orderByDesc('ends_at')
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'when' => $lesson->localStart()->format('D j M, H:i'),
                'closesAt' => $lesson->ends_at->addHours(48)->setTimezone(config('academy.timezone'))->format('D j M, H:i'),
            ])
            ->all();
    }
}
