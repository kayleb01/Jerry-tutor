<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Everything the student dashboard shows, built from the student's own
 * enrolments, lessons and attendance. Times are formatted in the academy timezone.
 */
class StudentDashboard
{
    /**
     * Maths strands shown on the dashboard. Quiz progress for each arrives in Phase 5.
     */
    private const STRANDS = [
        ['key' => 'number', 'name' => 'Number', 'summary' => 'Place value, the four operations, fractions, decimals and percentages.'],
        ['key' => 'algebra', 'name' => 'Algebra', 'summary' => 'Patterns, sequences, expressions and solving equations.'],
        ['key' => 'statistics', 'name' => 'Statistics', 'summary' => 'Tables, charts and graphs, averages and interpreting data.'],
        ['key' => 'geometry', 'name' => 'Geometry', 'summary' => 'Shapes, angles, position and direction, area and volume.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(User $student, bool $nextWeek = false): array
    {
        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $currentWeekStart = $now->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY);
        $weekStart = $currentWeekStart->addWeeks($nextWeek ? 1 : 0);
        $weekEnd = $weekStart->endOfWeek(CarbonImmutable::SUNDAY);
        $todayStart = $now->setTimezone($timezone)->startOfDay();

        $classIds = $student->enrolledClassGroups()->wherePivotNull('left_at')->pluck('class_groups.id');

        $lessonsBetween = fn (CarbonImmutable $from, CarbonImmutable $to): Collection => Lesson::query()
            ->whereIn('class_group_id', $classIds)
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->with(['classGroup', 'attendanceRecords' => fn (HasMany $query) => $query->where('user_id', $student->id)])
            ->orderBy('starts_at')
            ->get();

        $today = $lessonsBetween($todayStart, $todayStart->endOfDay())->map(fn (Lesson $lesson): array => $this->lessonCard($lesson, $now));
        $weekLessons = $lessonsBetween($weekStart, $weekEnd);
        $thisWeekLessons = $nextWeek ? $lessonsBetween($currentWeekStart, $currentWeekStart->endOfWeek(CarbonImmutable::SUNDAY)) : $weekLessons;
        $countable = $thisWeekLessons->reject(fn (Lesson $lesson): bool => $lesson->isCancelled());

        return [
            'header' => [
                'yearGroup' => $student->studentProfile?->year_group->label(),
                'weekLabel' => $this->weekLabel($weekStart, $weekEnd),
            ],
            'stats' => [
                ...$this->attendanceStats($student, $now),
                'classTime' => $this->duration($countable->sum(fn (Lesson $lesson): int => $this->minutes($lesson))),
                'lessonsThisWeek' => $countable->count(),
            ],
            'strands' => self::STRANDS,
            'today' => $today->values()->all(),
            'todayMinutes' => $today->reject(fn (array $card): bool => $card['state'] === 'cancelled')->sum('minutes'),
            'week' => $weekLessons
                ->groupBy(fn (Lesson $lesson): string => $lesson->localStart()->toDateString())
                ->map(function (Collection $lessons) use ($now): array {
                    $day = $lessons->first()->localStart();
                    $cards = $lessons->map(fn (Lesson $lesson): array => $this->lessonCard($lesson, $now));

                    return [
                        'key' => $day->toDateString(),
                        'name' => $day->format('l'),
                        'date' => $day->format('j M'),
                        'minutes' => $cards->reject(fn (array $card): bool => $card['state'] === 'cancelled')->sum('minutes'),
                        'lessons' => $cards->values()->all(),
                    ];
                })
                ->values()
                ->all(),
            'nextWeek' => $nextWeek,
        ];
    }

    /**
     * Attendance = attended / (attended + absent); on time = present / attended. Excused is left out. Last 30 days.
     *
     * @return array{attendance: int|null, onTime: int|null}
     */
    private function attendanceStats(User $student, CarbonImmutable $now): array
    {
        $statuses = AttendanceRecord::query()
            ->where('user_id', $student->id)
            ->whereHas('lesson', fn (Builder $query) => $query->whereBetween('starts_at', [$now->subDays(30), $now]))
            ->pluck('status');

        $present = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Present)->count();
        $late = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Late)->count();
        $absent = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Absent)->count();
        $attended = $present + $late;

        return [
            'attendance' => $attended + $absent === 0 ? null : (int) round($attended / ($attended + $absent) * 100),
            'onTime' => $attended === 0 ? null : (int) round($present / $attended * 100),
        ];
    }

    /**
     * @return array{id: int, className: string, time: string, location: string|null, minutes: int, state: string, attendance: string|null}
     */
    private function lessonCard(Lesson $lesson, CarbonImmutable $now): array
    {
        $state = match (true) {
            $lesson->isCancelled() => 'cancelled',
            $now->lessThan($lesson->starts_at) => 'upcoming',
            $now->lessThan($lesson->ends_at) => 'in_progress',
            default => 'finished',
        };

        return [
            'id' => $lesson->id,
            'className' => $lesson->classGroup->name,
            'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
            'location' => $lesson->classGroup->location,
            'minutes' => $this->minutes($lesson),
            'state' => $state,
            'attendance' => $lesson->attendanceRecords->first()?->status->value,
        ];
    }

    private function minutes(Lesson $lesson): int
    {
        return (int) $lesson->starts_at->diffInMinutes($lesson->ends_at);
    }

    private function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => "{$rest}m",
            $rest === 0 => "{$hours}h",
            default => "{$hours}h {$rest}m",
        };
    }

    private function weekLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->month === $end->month
            ? "Week of {$start->format('j')} – {$end->format('j F')}"
            : "Week of {$start->format('j F')} – {$end->format('j F')}";
    }
}
