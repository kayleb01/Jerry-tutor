<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Question;
use App\Models\User;
use App\Support\AdminDashboardFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Everything the admin dashboard shows. Today's classes are always today (narrowed by
 * class or tutor); the analytics follow the chosen period and filters, for students and
 * tutors alike. Times are in the academy timezone.
 */
class AdminDashboard
{
    /**
     * Quiz categories shown on the dashboard; activity fills in once the quiz platform launches.
     *
     * @var array<int, string>
     */
    private const QUIZ_CATEGORIES = ['KS2', 'KS3', '11+', 'SATs', 'GCSE'];

    public function __construct(private LatenessReportQuery $latenessReports) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $admin, ?AdminDashboardFilters $filters = null): array
    {
        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $localNow = $now->setTimezone($timezone);
        $filters ??= AdminDashboardFilters::fromQuery([], $now);

        $today = $this->todayLessons($localNow->startOfDay(), $now, $filters);
        $late = $this->latenessIn($filters, $filters->from, $filters->to)->with('user')->orderByDesc('lesson_date')->orderByDesc('arrived_at')->get();
        $attendanceRole = $filters->role ?? AttendeeRole::Student;
        $attendance = $this->attendanceShare($attendanceRole, $filters->from, $filters->to, $filters);
        [$previousFrom, $previousTo] = $filters->previous();
        $previousAttendance = $this->attendanceShare($attendanceRole, $previousFrom, $previousTo, $filters);

        return [
            'header' => [
                'date' => $localNow->format('l j F Y'),
                'greeting' => $this->greeting($localNow).', '.str($admin->name)->before(' ')->value(),
            ],
            'filters' => $filters->toProps(),
            'options' => $this->options(),
            'stats' => [
                'classesToday' => $today->reject(fn (array $lesson): bool => $lesson['state'] === 'cancelled')->count(),
                'runningNow' => $today->where('state', 'in_progress')->count(),
                'toStart' => $today->where('state', 'upcoming')->count(),
                'attendanceRole' => $attendanceRole->value,
                'attendance' => $attendance,
                'attendanceChange' => $attendance === null || $previousAttendance === null ? null : $attendance - $previousAttendance,
                'late' => $late->count(),
                'lateStudents' => $late->where('attendee_role', AttendeeRole::Student)->count(),
                'lateTutors' => $late->where('attendee_role', AttendeeRole::Tutor)->count(),
                ...$this->headcount($filters),
            ],
            'today' => $today->values()->all(),
            'lateness' => $late->take(6)->map(fn (LatenessReport $report): array => [
                'id' => $report->id,
                'name' => $report->user->name,
                'role' => $report->attendee_role->value,
                'className' => $report->class_name,
                'date' => $report->lesson_date->format('D j M'),
                'arrived' => $report->arrived_at->setTimezone($timezone)->format('H:i'),
                'minutesLate' => $report->minutes_late,
            ])->values()->all(),
            'repeatLateness' => $this->latenessReports->repeatOffenders(
                $filters->from->toDateString(),
                $filters->to->toDateString(),
                fn (Builder $query) => $this->scopeLateness($query, $filters),
            ),
            'trend' => $this->trend($filters, $now),
            'latestReports' => $this->latestReports($filters),
            'quizzes' => [
                'categories' => array_map(fn (string $label): array => ['label' => $label, 'count' => 0], self::QUIZ_CATEGORIES),
                'questionBank' => Question::published()->count(),
                'available' => false,
            ],
        ];
    }

    /**
     * @return array{periods: array<int, array{value: string, label: string}>, classes: array<int, array{value: int, label: string}>, tutors: array<int, array{value: int, label: string}>}
     */
    private function options(): array
    {
        return [
            'periods' => collect(AdminDashboardFilters::PERIODS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'classes' => ClassGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])->all(),
            'tutors' => User::query()->where('role', Role::Tutor)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $tutor): array => ['value' => $tutor->id, 'label' => $tutor->name])->all(),
        ];
    }

    /**
     * Restrict a lessons query to the chosen class and tutor.
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    private function scopeLessons(Builder $query, AdminDashboardFilters $filters): Builder
    {
        return $query
            ->when($filters->classId, fn (Builder $lessons, int $class) => $lessons->where('class_group_id', $class))
            ->when($filters->tutorId, fn (Builder $lessons, int $tutor) => $lessons->where('tutor_id', $tutor));
    }

    /**
     * @param  Builder<LatenessReport>  $query
     * @return Builder<LatenessReport>
     */
    private function scopeLateness(Builder $query, AdminDashboardFilters $filters): Builder
    {
        return $query
            ->when($filters->role, fn (Builder $reports, AttendeeRole $role) => $reports->where('attendee_role', $role))
            ->when($filters->classId || $filters->tutorId, fn (Builder $reports) => $reports->whereHas('lesson', fn (Builder $lesson) => $this->scopeLessons($lesson, $filters)));
    }

    /**
     * @return Builder<LatenessReport>
     */
    private function latenessIn(AdminDashboardFilters $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->scopeLateness(
            LatenessReport::query()
                ->whereDate('lesson_date', '>=', $from->toDateString())
                ->whereDate('lesson_date', '<=', $to->toDateString()),
            $filters,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function todayLessons(CarbonImmutable $todayStart, CarbonImmutable $now, AdminDashboardFilters $filters): Collection
    {
        return $this->scopeLessons(Lesson::query(), $filters)
            ->whereBetween('starts_at', [$todayStart->utc(), $todayStart->endOfDay()->utc()])
            ->with([
                'tutor',
                'classGroup' => fn (BelongsTo $query) => $query->withCount('activeStudents'),
                'attendanceRecords',
                'lessonReports',
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Lesson $lesson) use ($now): array {
                $tutorRecord = $lesson->attendanceRecords->firstWhere('attendee_role', AttendeeRole::Tutor);
                $students = $lesson->attendanceRecords->where('attendee_role', AttendeeRole::Student);

                return [
                    'id' => $lesson->id,
                    'time' => $lesson->localStart()->format('H:i'),
                    'className' => $lesson->classGroup->name,
                    'tutor' => $lesson->tutor?->name,
                    'state' => match (true) {
                        $lesson->isCancelled() => 'cancelled',
                        $now->lessThan($lesson->starts_at) => 'upcoming',
                        $now->lessThan($lesson->ends_at) => 'in_progress',
                        default => 'finished',
                    },
                    'tutorStatus' => $tutorRecord?->status->value,
                    'tutorMinutesLate' => $tutorRecord?->minutes_late,
                    'present' => $students->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])->count(),
                    'marked' => $students->isNotEmpty(),
                    'expected' => $lesson->classGroup->active_students_count,
                    'tutorReported' => $lesson->lessonReports->contains('author_role', AttendeeRole::Tutor),
                    'studentReports' => $lesson->lessonReports->where('author_role', AttendeeRole::Student)->count(),
                ];
            });
    }

    /**
     * Active students and tutors, narrowed to the chosen class or tutor's classes.
     *
     * @return array{activeStudents: int, activeTutors: int}
     */
    private function headcount(AdminDashboardFilters $filters): array
    {
        if ($filters->classId === null && $filters->tutorId === null) {
            return [
                'activeStudents' => User::query()->where('role', Role::Student)->count(),
                'activeTutors' => User::query()->where('role', Role::Tutor)->count(),
            ];
        }

        $classes = ClassGroup::query()
            ->when($filters->classId, fn (Builder $query, int $class) => $query->whereKey($class))
            ->when($filters->tutorId, fn (Builder $query, int $tutor) => $query->where('tutor_id', $tutor));

        return [
            'activeStudents' => User::query()
                ->where('role', Role::Student)
                ->whereHas('enrolledClassGroups', fn (Builder $query) => $query
                    ->whereIn('class_groups.id', (clone $classes)->select('id'))
                    ->whereNull('class_group_student.left_at'))
                ->count(),
            'activeTutors' => User::query()->where('role', Role::Tutor)->whereIn('id', (clone $classes)->select('tutor_id'))->count(),
        ];
    }

    /**
     * Share of attendances (on time or late) out of attended + absent; excused lessons don't count.
     */
    private function attendanceShare(AttendeeRole $role, CarbonImmutable $from, CarbonImmutable $to, AdminDashboardFilters $filters): ?int
    {
        $statuses = $this->statusesBetween($role, $from, $to, $filters);
        $attended = $statuses->filter(fn (AttendanceStatus $status): bool => in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late], true))->count();
        $counted = $attended + $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Absent)->count();

        return $counted === 0 ? null : (int) round($attended / $counted * 100);
    }

    /**
     * On-time share for students and tutors. Short periods (two weeks or less) show the six
     * weeks up to the period's end; longer ones are split by week, or by month beyond ~4 months.
     *
     * @return array{label: string, buckets: array<int, array{label: string, students: int|null, tutors: int|null}>}
     */
    private function trend(AdminDashboardFilters $filters, CarbonImmutable $now): array
    {
        $days = $filters->days();
        $end = $filters->to->lessThan($now) ? $filters->to : $now->setTimezone(config('academy.timezone'));

        [$label, $starts, $step, $format] = match (true) {
            $days <= 7 => ['Last 6 weeks', $this->steps($filters->to->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(5), $filters->to, '1 week'), '1 week', 'j M'],
            $days <= 14 => ['By day', $this->steps($filters->from, $filters->to, '1 day'), '1 day', 'j M'],
            $days <= 120 => ['By week', $this->steps($filters->from->startOfWeek(CarbonImmutable::MONDAY), $filters->to, '1 week'), '1 week', 'j M'],
            default => ['By month', $this->steps($filters->from->startOfMonth(), $filters->to, '1 month'), '1 month', 'M Y'],
        };

        return [
            'label' => $label,
            'buckets' => $starts->map(function (CarbonImmutable $start) use ($step, $end, $format, $filters): array {
                $bucketEnd = $start->add($step)->subSecond();
                $bucketEnd = $bucketEnd->greaterThan($end) ? $end : $bucketEnd;

                return [
                    'label' => $start->format($format),
                    'students' => $filters->role === AttendeeRole::Tutor ? null : $this->onTimeShare(AttendeeRole::Student, $start, $bucketEnd, $filters),
                    'tutors' => $filters->role === AttendeeRole::Student ? null : $this->onTimeShare(AttendeeRole::Tutor, $start, $bucketEnd, $filters),
                ];
            })->all(),
        ];
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private function steps(CarbonImmutable $from, CarbonImmutable $to, string $step): Collection
    {
        $starts = collect();

        for ($start = $from->startOfDay(); $start->lessThanOrEqualTo($to); $start = $start->add($step)) {
            $starts->push($start);
        }

        return $starts;
    }

    private function onTimeShare(AttendeeRole $role, CarbonImmutable $from, CarbonImmutable $to, AdminDashboardFilters $filters): ?int
    {
        $statuses = $this->statusesBetween($role, $from, $to, $filters);
        $onTime = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Present)->count();
        $arrived = $onTime + $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Late)->count();

        return $arrived === 0 ? null : (int) round($onTime / $arrived * 100);
    }

    /**
     * @return Collection<int, AttendanceStatus>
     */
    private function statusesBetween(AttendeeRole $role, CarbonImmutable $from, CarbonImmutable $to, AdminDashboardFilters $filters): Collection
    {
        return AttendanceRecord::query()
            ->where('attendee_role', $role)
            ->whereHas('lesson', fn (Builder $query) => $this->scopeLessons($query, $filters)
                ->where('starts_at', '>=', $from->utc())
                ->where('starts_at', '<=', $to->utc()))
            ->pluck('status');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestReports(AdminDashboardFilters $filters): array
    {
        return LessonReport::query()
            ->with(['author', 'lesson' => fn (BelongsTo $query) => $query->with('classGroup')])
            ->whereHas('lesson', fn (Builder $query) => $this->scopeLessons($query, $filters)
                ->where('starts_at', '>=', $filters->from->utc())
                ->where('starts_at', '<=', $filters->to->utc()))
            ->when($filters->role, fn (Builder $query, AttendeeRole $role) => $query->where('author_role', $role))
            ->latest('submitted_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (LessonReport $report): array => [
                'id' => $report->id,
                'author' => $report->author->name,
                'role' => $report->author_role->value,
                'className' => $report->lesson->classGroup->name,
                'submittedAt' => $report->submitted_at->setTimezone(config('academy.timezone'))->format('D j M, H:i'),
                'wentWell' => $report->went_well,
                'recommendations' => $report->recommendations,
            ])
            ->all();
    }

    private function greeting(CarbonImmutable $localNow): string
    {
        return match (true) {
            $localNow->hour < 12 => 'Good morning',
            $localNow->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
