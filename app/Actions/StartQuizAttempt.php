<?php

namespace App\Actions;

use App\Enums\QuizAttemptStatus;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\QuestionPicker;
use Illuminate\Support\Facades\DB;

/**
 * Opens a quiz for a student: resumes their unfinished attempt, or draws fresh questions and records
 * that the student has now seen them (so they don't come round again until the pool is used up).
 */
class StartQuizAttempt
{
    public function __construct(private QuestionPicker $picker) {}

    /**
     * Returns null when the quiz has no questions to give.
     */
    public function handle(Quiz $quiz, User $student): ?QuizAttempt
    {
        $unfinished = QuizAttempt::query()
            ->where('user_id', $student->id)
            ->where('quiz_id', $quiz->id)
            ->where('status', QuizAttemptStatus::InProgress)
            ->latest('id')
            ->first();

        if ($unfinished) {
            return $unfinished;
        }

        $questions = $this->picker->pick($quiz, $student);

        if ($questions->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($quiz, $student, $questions): QuizAttempt {
            $now = now();
            $attempt = QuizAttempt::create([
                'user_id' => $student->id,
                'quiz_id' => $quiz->id,
                'status' => QuizAttemptStatus::InProgress,
                'started_at' => $now,
                'max_score' => $questions->sum('marks'),
            ]);

            $attempt->questions()->createMany($questions->values()->map(fn (Question $question, int $index): array => [
                'question_id' => $question->id,
                'position' => $index + 1,
            ])->all());

            DB::table('question_exposures')->upsert(
                $questions->map(fn (Question $question): array => [
                    'user_id' => $student->id,
                    'question_id' => $question->id,
                    'times_seen' => 1,
                    'last_seen_at' => $now,
                ])->all(),
                ['user_id', 'question_id'],
                ['times_seen' => DB::raw('question_exposures.times_seen + 1'), 'last_seen_at' => $now],
            );

            Question::query()->whereKey($questions->pluck('id'))->increment('times_served');

            return $attempt;
        });
    }
}
