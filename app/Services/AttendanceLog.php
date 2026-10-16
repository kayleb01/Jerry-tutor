<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Support\AttendanceFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every attendance record in the chosen period, for students and tutors, with the period's totals.
 * Excused absences count as absences given with a reason.
 */
class AttendanceLog
{
    /**
     * The records, newest lesson first; within a lesson, the tutor and then students by name.
     *
     * @return Builder<AttendanceRecord>
     */
    public function records(AttendanceFilters $filters): Builder
    {
        return $this->filtered($filters)
            ->join('users', 'users.id', '=', 'attendance_records.user_id')
            ->select('attendance_records.*')
            ->with(['user' => fn ($query) => $query->withTrashed(), 'lesson.classGroup'])
            ->orderByDesc('lessons.starts_at')
            ->orderByDesc('lessons.id')
            ->orderByDesc('attendance_records.attendee_role')
            ->orderBy('users.name')
            ->orderBy('attendance_records.id');
    }

    /**
     * @return array{expected: int, present: int, absent: int, absentShare: float|null, withReason: int, late: int, lateStudents: int, lateTutors: int, averageMinutesLate: int|null}
     */
    public function summary(AttendanceFilters $filters): array
    {
        $groups = $this->filtered($filters)
            ->toBase()
            ->selectRaw('attendance_records.status, attendance_records.attendee_role, count(*) as total, coalesce(sum(attendance_records.minutes_late), 0) as minutes')
            ->groupBy('attendance_records.status', 'attendance_records.attendee_role')
            ->get();

        $count = fn (array $statuses, ?AttendeeRole $role = null): int => (int) $groups
            ->filter(fn (object $group): bool => in_array($group->status, array_map(fn (AttendanceStatus $status): string => $status->value, $statuses), true)
                && ($role === null || $group->attendee_role === $role->value))
            ->sum('total');

        $expected = (int) $groups->sum('total');
        $absent = $count([AttendanceStatus::Absent, AttendanceStatus::Excused]);
        $late = $count([AttendanceStatus::Late]);
        $lateMinutes = (int) $groups->where('status', AttendanceStatus::Late->value)->sum('minutes');

        return [
            'expected' => $expected,
            'present' => $count([AttendanceStatus::Present, AttendanceStatus::Late]),
            'absent' => $absent,
            'absentShare' => $expected === 0 ? null : round($absent / $expected * 100, 1),
            'withReason' => $count([AttendanceStatus::Excused]),
            'late' => $late,
            'lateStudents' => $count([AttendanceStatus::Late], AttendeeRole::Student),
            'lateTutors' => $count([AttendanceStatus::Late], AttendeeRole::Tutor),
            'averageMinutesLate' => $late === 0 ? null : (int) round($lateMinutes / $late),
        ];
    }

    /**
     * @return array{id: int, name: string, role: string, className: string, date: string, scheduled: string, arrived: string|null, status: string, minutesLate: int|null}
     */
    public function row(AttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'name' => $record->user?->name ?? 'Deleted user',
            'role' => $record->attendee_role->value,
            'className' => $record->lesson->classGroup->name,
            'date' => $record->lesson->localStart()->format('D j M'),
            'scheduled' => $record->lesson->localStart()->format('H:i'),
            'arrived' => $record->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i'),
            'status' => $record->status->value,
            'minutesLate' => $record->minutes_late,
        ];
    }

    /**
     * Today's lessons that still run, for opening a register.
     *
     * @return array<int, array{id: int, time: string, className: string}>
     */
    public function todayLessons(?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(config('academy.timezone'))->startOfDay();

        return Lesson::query()
            ->where('status', '!=', LessonStatus::Cancelled)
            ->whereBetween('starts_at', [$today->utc(), $today->endOfDay()->utc()])
            ->with('classGroup')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'time' => $lesson->localStart()->format('H:i'),
                'className' => $lesson->classGroup->name,
            ])
            ->all();
    }

    /**
     * @return Builder<AttendanceRecord>
     */
    private function filtered(AttendanceFilters $filters): Builder
    {
        $period = $filters->period;

        return AttendanceRecord::query()
            ->join('lessons', 'lessons.id', '=', 'attendance_records.lesson_id')
            ->where('lessons.starts_at', '>=', $period->from->utc())
            ->where('lessons.starts_at', '<=', $period->to->utc())
            ->when($period->classId, fn (Builder $query, int $class) => $query->where('lessons.class_group_id', $class))
            ->when($period->tutorId, fn (Builder $query, int $tutor) => $query->where('lessons.tutor_id', $tutor))
            ->when($period->role, fn (Builder $query, AttendeeRole $role) => $query->where('attendance_records.attendee_role', $role))
            ->when($filters->stage, fn (Builder $query, $stage) => $query->whereIn('lessons.class_group_id', ClassGroup::query()
                ->whereIn('year_group', collect(YearGroup::cases())->filter(fn (YearGroup $year): bool => $year->keyStage() === $stage)->map->value->values())
                ->select('id')));
    }
}
