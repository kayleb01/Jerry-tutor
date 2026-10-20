<?php

namespace App\Services;

use App\Enums\QuizAttemptStatus;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Everything the student dashboard shows, built from the student's own
 * enrolments, lessons and attendance. Times are formatted in the academy timezone.
 */
class StudentDashboard
{
    public function __construct(private AttendanceStats $attendanceStats) {}

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
            ->with([
                'classGroup',
                'slot',
                'attendanceRecords' => fn (HasMany $query) => $query->where('user_id', $student->id),
                'lessonReports' => fn (HasMany $query) => $query->where('author_id', $student->id),
            ])
            ->orderBy('starts_at')
            ->get();

        $today = $lessonsBetween($todayStart, $todayStart->endOfDay())->map(fn (Lesson $lesson): array => $this->lessonCard($lesson, $student, $now));
        $weekLessons = $lessonsBetween($weekStart, $weekEnd);
        $thisWeekLessons = $nextWeek ? $lessonsBetween($currentWeekStart, $currentWeekStart->endOfWeek(CarbonImmutable::SUNDAY)) : $weekLessons;
        $countable = $thisWeekLessons->reject(fn (Lesson $lesson): bool => $lesson->isCancelled());

        return [
            'header' => [
                'yearGroup' => $student->studentProfile?->year_group->label(),
                'weekLabel' => $this->weekLabel($weekStart, $weekEnd),
            ],
            'stats' => [
                ...collect($this->attendanceStats->forUser($student, $now))->only(['attendance', 'onTime'])->all(),
                'classTime' => $this->duration($countable->sum(fn (Lesson $lesson): int => $this->minutes($lesson))),
                'lessonsThisWeek' => $countable->count(),
            ],
            'strands' => $this->strands($student),
            'quizAccess' => $student->hasQuizAccess(),
            'today' => $today->values()->all(),
            'todayMinutes' => $today->reject(fn (array $card): bool => $card['state'] === 'cancelled')->sum('minutes'),
            'week' => $weekLessons
                ->groupBy(fn (Lesson $lesson): string => $lesson->localStart()->toDateString())
                ->map(function (Collection $lessons) use ($student, $now): array {
                    $day = $lessons->first()->localStart();
                    $cards = $lessons->map(fn (Lesson $lesson): array => $this->lessonCard($lesson, $student, $now));

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
     * @return array{id: int, className: string, time: string, location: string|null, minutes: int, state: string, attendance: string|null, canReport: bool, reported: bool}
     */
    private function lessonCard(Lesson $lesson, User $student, CarbonImmutable $now): array
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
            'location' => $lesson->slot->location,
            'minutes' => $this->minutes($lesson),
            'state' => $state,
            'attendance' => $lesson->attendanceRecords->first()?->status->value,
            'canReport' => $student->can('submitReport', $lesson),
            'reported' => $lesson->lessonReports->isNotEmpty(),
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

    /**
     * The four strands, each linked to the student's quiz for that strand with their last score.
     *
     * @return array<int, array{key: string, name: string, summary: string, quizSlug: string|null, lastScore: string|null}>
     */
    private function strands(User $student): array
    {
        $year = $student->studentProfile?->year_group;
        $slugs = collect(self::STRANDS)->mapWithKeys(fn (array $strand): array => [$strand['key'] => QuizCatalogue::strandQuizSlug($year, $strand['key'])]);

        $latest = QuizAttempt::query()
            ->where('user_id', $student->id)
            ->where('status', QuizAttemptStatus::Submitted)
            ->whereHas('quiz', fn ($quiz) => $quiz->whereIn('slug', $slugs->filter()->values()))
            ->with('quiz:id,slug')
            ->latest('submitted_at')
            ->get()
            ->unique('quiz_id')
            ->keyBy(fn (QuizAttempt $attempt): string => $attempt->quiz->slug);

        return array_map(function (array $strand) use ($slugs, $latest): array {
            $slug = $slugs[$strand['key']];
            $attempt = $slug ? $latest->get($slug) : null;

            return [
                ...$strand,
                'quizSlug' => $slug,
                'lastScore' => $attempt ? "{$attempt->score}/{$attempt->max_score}" : null,
            ];
        }, self::STRANDS);
    }
}
