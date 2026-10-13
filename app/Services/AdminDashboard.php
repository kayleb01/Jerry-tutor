<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\AttendanceRecord;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Everything the admin dashboard shows: today's classes, punctuality for students
 * and tutors alike, recent reports and who is enrolled. Times are in the academy timezone.
 */
class AdminDashboard
{
    public function __construct(private LatenessReportQuery $latenessReports) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $admin): array
    {
        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $localNow = $now->setTimezone($timezone);
        $todayStart = $localNow->startOfDay();
        $weekStart = $localNow->startOfWeek(CarbonImmutable::MONDAY);

        $today = $this->todayLessons($todayStart, $now);
        $lateToday = LatenessReport::query()
            ->with('user')
            ->whereDate('lesson_date', $todayStart->toDateString())
            ->orderByDesc('arrived_at')
            ->get();
        $thisWeek = $this->studentAttendance($weekStart, $now);
        $lastWeek = $this->studentAttendance($weekStart->subWeek(), $now->subWeek());

        return [
            'header' => [
                'date' => $localNow->format('l j F Y'),
                'greeting' => $this->greeting($localNow).', '.str($admin->name)->before(' ')->value(),
            ],
            'stats' => [
                'classesToday' => $today->reject(fn (array $lesson): bool => $lesson['state'] === 'cancelled')->count(),
                'runningNow' => $today->where('state', 'in_progress')->count(),
                'toStart' => $today->where('state', 'upcoming')->count(),
                'attendance' => $thisWeek,
                'attendanceChange' => $thisWeek === null || $lastWeek === null ? null : $thisWeek - $lastWeek,
                'lateToday' => $lateToday->count(),
                'lateStudents' => $lateToday->where('attendee_role', AttendeeRole::Student)->count(),
                'lateTutors' => $lateToday->where('attendee_role', AttendeeRole::Tutor)->count(),
                'activeStudents' => User::query()->where('role', Role::Student)->count(),
                'activeTutors' => User::query()->where('role', Role::Tutor)->count(),
            ],
            'today' => $today->values()->all(),
            'lateness' => $lateToday->map(fn (LatenessReport $report): array => [
                'id' => $report->id,
                'name' => $report->user->name,
                'role' => $report->attendee_role->value,
                'className' => $report->class_name,
                'arrived' => $report->arrived_at->setTimezone($timezone)->format('H:i'),
                'minutesLate' => $report->minutes_late,
            ])->values()->all(),
            'repeatLateness' => $this->latenessReports->repeatOffenders(),
            'onTimeWeeks' => $this->onTimeWeeks($weekStart, $now),
            'latestReports' => $this->latestReports(),
            'studentsByStage' => $this->studentsByStage(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function todayLessons(CarbonImmutable $todayStart, CarbonImmutable $now): Collection
    {
        return Lesson::query()
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
     * Share of student attendances (on time or late) from the start of the week until now.
     */
    private function studentAttendance(CarbonImmutable $from, CarbonImmutable $until): ?int
    {
        $statuses = $this->statusesBetween(AttendeeRole::Student, $from, $until);
        $attended = $statuses->filter(fn (AttendanceStatus $status): bool => in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late], true))->count();
        $counted = $attended + $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Absent)->count();

        return $counted === 0 ? null : (int) round($attended / $counted * 100);
    }

    /**
     * On-time share of arrivals for students and tutors in each of the last six weeks, oldest first.
     *
     * @return array<int, array{label: string, students: int|null, tutors: int|null}>
     */
    private function onTimeWeeks(CarbonImmutable $currentWeekStart, CarbonImmutable $now): array
    {
        return collect(range(5, 0))
            ->map(function (int $weeksAgo) use ($currentWeekStart, $now): array {
                $start = $currentWeekStart->subWeeks($weeksAgo);
                $end = $weeksAgo === 0 ? $now : $start->addWeek();

                return [
                    'label' => $start->format('j M'),
                    'students' => $this->onTimeShare(AttendeeRole::Student, $start, $end),
                    'tutors' => $this->onTimeShare(AttendeeRole::Tutor, $start, $end),
                ];
            })
            ->all();
    }

    private function onTimeShare(AttendeeRole $role, CarbonImmutable $from, CarbonImmutable $until): ?int
    {
        $statuses = $this->statusesBetween($role, $from, $until);
        $onTime = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Present)->count();
        $arrived = $onTime + $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Late)->count();

        return $arrived === 0 ? null : (int) round($onTime / $arrived * 100);
    }

    /**
     * @return Collection<int, AttendanceStatus>
     */
    private function statusesBetween(AttendeeRole $role, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return AttendanceRecord::query()
            ->where('attendee_role', $role)
            ->whereHas('lesson', fn (Builder $query) => $query->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $until->utc()))
            ->pluck('status');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestReports(): array
    {
        return LessonReport::query()
            ->with(['author', 'lesson' => fn (BelongsTo $query) => $query->with('classGroup')])
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

    /**
     * Active students per key stage, in key stage order.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function studentsByStage(): array
    {
        return StudentProfile::query()
            ->whereHas('user', fn (Builder $query) => $query->where('role', Role::Student))
            ->pluck('year_group')
            ->groupBy(fn (YearGroup $yearGroup): string => $yearGroup->keyStage()->value)
            ->sortKeys()
            ->map(fn (Collection $group): array => ['label' => $group->first()->keyStage()->label(), 'count' => $group->count()])
            ->values()
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
