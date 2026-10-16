<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\QuizAttemptStatus;
use App\Enums\YearGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A heads-up for a tutor: students in their active classes who were absent or late, or who are doing well in a topic.
 * Only attendance a person entered counts, and only the last 90 days of lessons and quizzes.
 */
class StudentsToWatch
{
    /** How many students are listed. */
    public const LIMIT = 5;

    /** How far back the evidence goes, in days. */
    public const DAYS = 90;

    /** Quizzes needed in one strand before a high average means anything. */
    private const MIN_QUIZZES = 3;

    /** The average, in percent, from which a strand counts as going well. */
    private const HIGH_AVERAGE = 85;

    /**
     * @return array<int, array{id: int, name: string, yearGroup: string|null, reasons: string}>
     */
    public function for(User $tutor, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays(self::DAYS)->utc();

        $students = User::query()
            ->with('studentProfile')
            ->whereIn('id', DB::table('class_group_student')
                ->join('class_groups', 'class_groups.id', '=', 'class_group_student.class_group_id')
                ->where('class_groups.tutor_id', $tutor->id)
                ->where('class_groups.is_active', true)
                ->whereNull('class_group_student.left_at')
                ->select('class_group_student.student_id'))
            ->get()
            ->keyBy('id');

        if ($students->isEmpty()) {
            return [];
        }

        $counts = $this->attendanceCounts($tutor, $students->keys()->all(), $since, $now);
        $lastRecords = $this->lastRecords($tutor, $students->keys()->all(), $since, $now);
        $strands = $this->bestStrands($students->keys()->all(), $since, $now);

        $entries = [];

        foreach ($students as $id => $student) {
            $absent = (int) ($counts[$id]->absent ?? 0);
            $late = (int) ($counts[$id]->late ?? 0);
            $last = $lastRecords[$id] ?? null;
            $yearGroup = $student->studentProfile?->year_group;
            $parts = $this->attendanceParts($absent, $late, $last?->status === AttendanceStatus::Absent->value, $last?->notes);
            $hasAttendanceParts = $parts !== [];
            $strand = $strands[$id] ?? null;

            if ($strand !== null) {
                $parts[] = $this->quizPart($strand, $yearGroup);
            }

            if ($parts === []) {
                continue;
            }

            $entries[] = [
                'entry' => ['id' => $id, 'name' => $student->name, 'yearGroup' => $yearGroup?->label(), 'reasons' => implode(' · ', $parts)],
                'concerns' => $absent + $late,
                'hasAttendance' => $hasAttendanceParts,
                'average' => $strand?->average ?? 0,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$b['hasAttendance'], $b['concerns'], $b['average'], $a['entry']['name']]
            <=> [$a['hasAttendance'], $a['concerns'], $a['average'], $b['entry']['name']]);

        return array_map(fn (array $item): array => $item['entry'], array_slice($entries, 0, self::LIMIT));
    }

    /**
     * @return array<int, string>
     */
    private function attendanceParts(int $absent, int $late, bool $absentLast, ?string $note): array
    {
        $parts = [];
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($absentLast) {
            $parts[] = $absent >= 2 ? "Absent last class ({$absent} times in 90 days)" : 'Absent last class';

            if ($note !== null) {
                $parts[] = $note;
            }
        } elseif ($absent >= 2) {
            $parts[] = "Absent {$absent} times in 90 days";
        }

        if ($late >= 2) {
            $parts[] = $late === 2 ? 'Late twice in 90 days' : "Late {$late} times in 90 days";
        }

        return $parts;
    }

    private function quizPart(object $strand, ?YearGroup $yearGroup): string
    {
        $next = $yearGroup?->defaultDifficulty()?->next();

        return "{$strand->name} quizzes {$strand->average}% — ready for ".($next?->label() ?? 'harder questions');
    }

    /**
     * Person-entered student records in this tutor's lessons within the window.
     *
     * @param  array<int, int>  $studentIds
     */
    private function recordsQuery(User $tutor, array $studentIds, CarbonImmutable $since, CarbonImmutable $now): Builder
    {
        return DB::table('attendance_records')
            ->join('lessons', 'lessons.id', '=', 'attendance_records.lesson_id')
            ->where('lessons.tutor_id', $tutor->id)
            ->whereBetween('lessons.starts_at', [$since, $now->utc()])
            ->where('attendance_records.attendee_role', AttendeeRole::Student->value)
            ->whereNotNull('attendance_records.recorded_by_id')
            ->whereIn('attendance_records.user_id', $studentIds);
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return Collection<int, object{absent: int|string, late: int|string}>
     */
    private function attendanceCounts(User $tutor, array $studentIds, CarbonImmutable $since, CarbonImmutable $now)
    {
        return $this->recordsQuery($tutor, $studentIds, $since, $now)
            ->groupBy('attendance_records.user_id')
            ->selectRaw('attendance_records.user_id, sum(case when attendance_records.status = ? then 1 else 0 end) as absent, sum(case when attendance_records.status = ? then 1 else 0 end) as late', [AttendanceStatus::Absent->value, AttendanceStatus::Late->value])
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Each student's most recent person-entered record.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, object{status: string, notes: string|null}>
     */
    private function lastRecords(User $tutor, array $studentIds, CarbonImmutable $since, CarbonImmutable $now)
    {
        return $this->recordsQuery($tutor, $studentIds, $since, $now)
            ->whereNotExists(function (Builder $query) use ($tutor, $since, $now): void {
                $query->from('attendance_records as later_records')
                    ->join('lessons as later_lessons', 'later_lessons.id', '=', 'later_records.lesson_id')
                    ->whereColumn('later_records.user_id', 'attendance_records.user_id')
                    ->where('later_lessons.tutor_id', $tutor->id)
                    ->whereBetween('later_lessons.starts_at', [$since, $now->utc()])
                    ->where('later_records.attendee_role', AttendeeRole::Student->value)
                    ->whereNotNull('later_records.recorded_by_id')
                    ->where(fn (Builder $newer) => $newer
                        ->whereColumn('later_lessons.starts_at', '>', 'lessons.starts_at')
                        ->orWhere(fn (Builder $tie) => $tie
                            ->whereColumn('later_lessons.starts_at', 'lessons.starts_at')
                            ->whereColumn('later_records.id', '>', 'attendance_records.id')));
            })
            ->select('attendance_records.user_id', 'attendance_records.status', 'attendance_records.notes')
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Each student's best strand: three or more submitted quizzes in the window averaging 85% or more.
     *
     * @param  array<int, int>  $studentIds
     * @return Collection<int, object{name: string, average: int}>
     */
    private function bestStrands(array $studentIds, CarbonImmutable $since, CarbonImmutable $now)
    {
        return DB::table('quiz_attempts')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('strands', 'strands.id', '=', 'quizzes.strand_id')
            ->whereIn('quiz_attempts.user_id', $studentIds)
            ->where('quiz_attempts.status', QuizAttemptStatus::Submitted->value)
            ->where('quiz_attempts.max_score', '>', 0)
            ->whereBetween('quiz_attempts.submitted_at', [$since, $now->utc()])
            ->groupBy('quiz_attempts.user_id', 'strands.id', 'strands.name')
            ->havingRaw('count(*) >= ?', [self::MIN_QUIZZES])
            ->selectRaw('quiz_attempts.user_id, strands.name, avg(quiz_attempts.score * 100.0 / quiz_attempts.max_score) as average')
            ->get()
            ->map(function (object $row): object {
                $row->average = (int) round($row->average);

                return $row;
            })
            ->filter(fn (object $row): bool => $row->average >= self::HIGH_AVERAGE)
            ->sortBy([['average', 'desc'], ['name', 'asc']])
            ->unique('user_id')
            ->keyBy('user_id');
    }
}
