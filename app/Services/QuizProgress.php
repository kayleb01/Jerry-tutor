<?php

namespace App\Services;

use App\Enums\QuizAttemptStatus;
use App\Models\QuizAttempt;
use App\Models\Strand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How a student is getting on with quizzes: recent scores, and how often they're right in each strand.
 */
class QuizProgress
{
    /** How many recent quizzes are listed and averaged. */
    public const RECENT = 5;

    /**
     * @return array{thisMonth: int, averagePercentage: int|null, strands: array<int, array{key: string, name: string, correct: int, total: int, percentage: int|null}>, recent: array<int, array{id: int, title: string, score: string, percentage: int, date: string}>}
     */
    public function forStudent(User $student, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $submitted = QuizAttempt::query()->where('user_id', $student->id)->where('status', QuizAttemptStatus::Submitted);

        $recent = (clone $submitted)
            ->with('quiz:id,title')
            ->latest('submitted_at')
            ->latest('id')
            ->limit(self::RECENT)
            ->get();

        return [
            'thisMonth' => (clone $submitted)->where('submitted_at', '>', $now->subDays(30))->count(),
            'averagePercentage' => $recent->isEmpty() ? null : (int) round($recent->avg(fn (QuizAttempt $attempt): int => $attempt->percentage() ?? 0)),
            'strands' => $this->strands($student),
            'recent' => $recent->map(fn (QuizAttempt $attempt): array => [
                'id' => $attempt->id,
                'title' => $attempt->quiz->title,
                'score' => "{$attempt->score}/{$attempt->max_score}",
                'percentage' => $attempt->percentage() ?? 0,
                'date' => $attempt->submitted_at->setTimezone(config('academy.timezone'))->format('D j M'),
            ])->all(),
        ];
    }

    /**
     * Every strand, with the share of the student's finished-quiz questions they got right in it.
     *
     * @return array<int, array{key: string, name: string, correct: int, total: int, percentage: int|null}>
     */
    private function strands(User $student): array
    {
        $totals = DB::table('quiz_attempt_questions')
            ->join('quiz_attempts', 'quiz_attempts.id', '=', 'quiz_attempt_questions.quiz_attempt_id')
            ->join('questions', 'questions.id', '=', 'quiz_attempt_questions.question_id')
            ->join('topics', 'topics.id', '=', 'questions.topic_id')
            ->where('quiz_attempts.user_id', $student->id)
            ->where('quiz_attempts.status', QuizAttemptStatus::Submitted->value)
            ->groupBy('topics.strand_id')
            ->selectRaw('topics.strand_id, count(*) as total, sum(case when quiz_attempt_questions.is_correct then 1 else 0 end) as correct')
            ->get()
            ->keyBy('strand_id');

        return Strand::query()->orderBy('sort_order')->get()->map(function (Strand $strand) use ($totals): array {
            $total = (int) ($totals[$strand->id]->total ?? 0);
            $correct = (int) ($totals[$strand->id]->correct ?? 0);

            return [
                'key' => $strand->slug,
                'name' => $strand->name,
                'correct' => $correct,
                'total' => $total,
                'percentage' => $total === 0 ? null : (int) round($correct / $total * 100),
            ];
        })->all();
    }
}
