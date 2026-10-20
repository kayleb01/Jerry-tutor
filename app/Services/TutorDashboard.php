<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Everything the tutor dashboard shows: the next class, four figures, this week's classes and students to watch.
 * Times are formatted in the academy timezone.
 */
class TutorDashboard
{
    public function __construct(private TutorReports $tutorReports, private StudentsToWatch $studentsToWatch) {}

    /**
     * @return array{
     *     header: array{date: string, greeting: string, now: string},
     *     nextLesson: array<string, mixed>|null,
     *     stats: array{
     *         punctuality: array{percent: int|null, onTime: int, attended: int},
     *         week: array{total: int, done: int, toGo: int},
     *         reports: array{count: int, oldest: string|null},
     *         studentAttendance: int|null,
     *     },
     *     week: array<int, array<string, mixed>>,
     *     studentsToWatch: array<int, array{id: int, name: string, yearGroup: string|null, reasons: string}>,
     * }
     */
    public function for(User $tutor, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone(config('academy.timezone'));
        $weekStart = $local->startOfWeek(CarbonImmutable::MONDAY);

        $weekLessons = $this->weekLessons($tutor, $weekStart, $weekStart->endOfWeek(CarbonImmutable::SUNDAY));
        $needed = $this->tutorReports->needingReport($tutor, $now)
            ->whereIn('lessons.id', $weekLessons->modelKeys())
            ->pluck('lessons.id')
            ->all();
        $countable = $weekLessons->reject(fn (Lesson $lesson): bool => $lesson->isCancelled());
        $done = $countable->filter(fn (Lesson $lesson): bool => $lesson->ends_at->lessThanOrEqualTo($now))->count();

        return [
            'header' => [
                'date' => $local->format('l j F Y'),
                'greeting' => $this->greeting($local).', '.$tutor->name,
                'now' => $local->format('H:i'),
            ],
            'nextLesson' => $this->nextLesson($tutor, $now, $local),
            'stats' => [
                'punctuality' => $this->punctuality($tutor, $now),
                'week' => ['total' => $countable->count(), 'done' => $done, 'toGo' => $countable->count() - $done],
                'reports' => $this->reportsToSubmit($tutor, $now),
                'studentAttendance' => $this->studentAttendance($tutor, $now),
            ],
            'week' => $weekLessons->map(fn (Lesson $lesson): array => $this->weekRow($lesson, $tutor, $now, $local, in_array($lesson->id, $needed, true)))->all(),
            'studentsToWatch' => $this->studentsToWatch->for($tutor, $now),
        ];
    }

    private function greeting(CarbonImmutable $local): string
    {
        return match (true) {
            $local->hour < 12 => 'Good morning',
            $local->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextLesson(User $tutor, CarbonImmutable $now, CarbonImmutable $local): ?array
    {
        $endOfDay = $local->endOfDay()->utc();

        $query = fn () => $tutor->lessonsTaught()
            ->where('status', '!=', LessonStatus::Cancelled)
            ->with([
                'classGroup' => fn (BelongsTo $query) => $query->withCount('activeStudents'),
                'slot',
                'attendanceRecords' => fn (HasMany $query) => $query->where('user_id', $tutor->id)->where('attendee_role', AttendeeRole::Tutor),
            ])
            ->orderBy('starts_at');

        $isToday = true;
        $lesson = $query()->where('starts_at', '<=', $endOfDay)->where('ends_at', '>', $now->utc())->first();

        if ($lesson === null) {
            $isToday = false;
            $lesson = $query()->where('starts_at', '>', $endOfDay)->first();
        }

        if ($lesson === null) {
            return null;
        }

        $own = $lesson->attendanceRecords->first();

        return [
            'id' => $lesson->id,
            'className' => $lesson->classGroup->name,
            'time' => $this->timeRange($lesson),
            'location' => $lesson->slot->location,
            'students' => $lesson->classGroup->active_students_count,
            'startsAt' => $lesson->starts_at->toIso8601String(),
            'dateLabel' => $lesson->starts_at->setTimezone(config('academy.timezone'))->format('l j M'),
            'state' => $lesson->starts_at->lessThanOrEqualTo($now) ? 'in_progress' : 'upcoming',
            'isToday' => $isToday,
            'canCheckIn' => $isToday && $own === null && $tutor->can('checkIn', $lesson),
            'checkedInAt' => $own?->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i'),
            'isLate' => $own?->status === AttendanceStatus::Late,
            'minutesLate' => $own?->status === AttendanceStatus::Late ? $own->minutes_late : null,
        ];
    }

    /**
     * @return array{percent: int|null, onTime: int, attended: int}
     */
    private function punctuality(User $tutor, CarbonImmutable $now): array
    {
        $totals = $this->recordsInWindow($tutor, $now)
            ->where('attendance_records.attendee_role', AttendeeRole::Tutor)
            ->where('attendance_records.user_id', $tutor->id)
            ->whereIn('attendance_records.status', [AttendanceStatus::Present, AttendanceStatus::Late])
            ->selectRaw('count(*) as attended, coalesce(sum(case when attendance_records.status = ? then 1 else 0 end), 0) as on_time', [AttendanceStatus::Present->value])
            ->first();

        $attended = (int) $totals->attended;
        $onTime = (int) $totals->on_time;

        return ['percent' => $attended === 0 ? null : (int) round($onTime / $attended * 100), 'onTime' => $onTime, 'attended' => $attended];
    }

    private function studentAttendance(User $tutor, CarbonImmutable $now): ?int
    {
        $totals = $this->recordsInWindow($tutor, $now)
            ->where('attendance_records.attendee_role', AttendeeRole::Student)
            ->whereNotNull('attendance_records.recorded_by_id')
            ->whereIn('attendance_records.status', [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Absent])
            ->selectRaw('count(*) as counted, coalesce(sum(case when attendance_records.status = ? then 0 else 1 end), 0) as attended', [AttendanceStatus::Absent->value])
            ->first();

        return (int) $totals->counted === 0 ? null : (int) round($totals->attended / $totals->counted * 100);
    }

    /**
     * Attendance records on the tutor's uncancelled lessons that started in the last 90 days.
     *
     * @return Builder<AttendanceRecord>
     */
    private function recordsInWindow(User $tutor, CarbonImmutable $now): Builder
    {
        return AttendanceRecord::query()
            ->join('lessons', 'lessons.id', '=', 'attendance_records.lesson_id')
            ->where('lessons.tutor_id', $tutor->id)
            ->where('lessons.status', '!=', LessonStatus::Cancelled->value)
            ->where('lessons.starts_at', '>=', $now->subDays(StudentsToWatch::DAYS)->utc())
            ->where('lessons.starts_at', '<=', $now->utc());
    }

    /**
     * @return array{count: int, oldest: string|null}
     */
    private function reportsToSubmit(User $tutor, CarbonImmutable $now): array
    {
        $count = $this->tutorReports->needingReport($tutor, $now)->count();
        $oldest = $count === 0
            ? null
            : $this->tutorReports->needingReport($tutor, $now)->with('classGroup')->reorder('lessons.starts_at')->first();

        return ['count' => $count, 'oldest' => $oldest === null ? null : $this->oldestLabel($oldest, $now)];
    }

    /**
     * "{class name} (Monday)": the day name within a week, otherwise "(12 Oct)".
     */
    private function oldestLabel(Lesson $lesson, CarbonImmutable $now): string
    {
        $name = $lesson->classGroup->name;
        $when = $lesson->starts_at->greaterThan($now->subDays(7)) ? $lesson->localStart()->format('l') : $lesson->localStart()->format('j M');

        return "{$name} ({$when})";
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function weekLessons(User $tutor, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $studentRecords = fn (Builder $query) => $query
            ->where('attendee_role', AttendeeRole::Student)
            ->whereNotNull('recorded_by_id');

        return $tutor->lessonsTaught()
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->with([
                'classGroup' => fn (BelongsTo $query) => $query->withCount('activeStudents'),
                'attendanceRecords' => fn (HasMany $query) => $query->where('user_id', $tutor->id)->where('attendee_role', AttendeeRole::Tutor),
                'reportsIncludingDrafts' => fn (HasMany $query) => $query->where('author_id', $tutor->id)->where('author_role', AttendeeRole::Tutor),
            ])
            ->withCount([
                'attendanceRecords as taken_count' => $studentRecords,
                'attendanceRecords as present_count' => fn (Builder $query) => $studentRecords($query)->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late]),
                'attendanceRecords as counted_count' => fn (Builder $query) => $studentRecords($query)->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Absent]),
            ])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array{id: int, day: string, time: string, className: string, state: string, summary: string, report: string|null, canOpenRegister: bool}
     */
    private function weekRow(Lesson $lesson, User $tutor, CarbonImmutable $now, CarbonImmutable $local, bool $needsReport): array
    {
        $isToday = $lesson->localStart()->isSameDay($local);
        $state = match (true) {
            $lesson->isCancelled() => 'cancelled',
            $lesson->ends_at->lessThanOrEqualTo($now) => 'past',
            $isToday => 'today',
            default => 'upcoming',
        };

        return [
            'id' => $lesson->id,
            'day' => $isToday ? 'Today' : $lesson->localStart()->format('D'),
            'time' => $this->timeRange($lesson),
            'className' => $lesson->classGroup->name,
            'state' => $state,
            'summary' => $this->summary($lesson, $state, $now),
            'report' => $this->reportStatus($lesson, $state, $needsReport),
            'canOpenRegister' => $state === 'today' && $tutor->can('viewRegister', $lesson),
        ];
    }

    private function summary(Lesson $lesson, string $state, CarbonImmutable $now): string
    {
        if ($state === 'cancelled') {
            return 'Cancelled';
        }

        if ($state === 'upcoming') {
            return $lesson->classGroup->active_students_count.' '.($lesson->classGroup->active_students_count === 1 ? 'student' : 'students');
        }

        if ($state === 'today') {
            if ($lesson->starts_at->lessThanOrEqualTo($now)) {
                return 'In progress';
            }

            $minutes = (int) ceil($now->diffInSeconds($lesson->starts_at) / 60);

            return 'Starts in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        }

        if ($lesson->taken_count === 0) {
            return 'Register not taken';
        }

        if ($lesson->counted_count === 0) {
            return 'Everyone excused';
        }

        $own = $lesson->attendanceRecords->first();
        $punctuality = match ($own?->status) {
            AttendanceStatus::Present => 'you were on time',
            AttendanceStatus::Late => $own->minutes_late === null ? 'you were late' : "you were {$own->minutes_late} min late",
            default => "you didn't check in",
        };

        return "{$lesson->present_count} of {$lesson->counted_count} present · {$punctuality}";
    }

    private function reportStatus(Lesson $lesson, string $state, bool $needsReport): ?string
    {
        if ($state === 'cancelled') {
            return null;
        }

        $reports = $lesson->reportsIncludingDrafts;

        return match (true) {
            $reports->contains(fn (LessonReport $report): bool => ! $report->isDraft()) => 'submitted',
            $reports->isNotEmpty() => 'draft',
            $needsReport => 'needed',
            default => null,
        };
    }

    private function timeRange(Lesson $lesson): string
    {
        return $lesson->localStart()->format('H:i').' – '.$lesson->localEnd()->format('H:i');
    }
}
