<?php

namespace App\Actions;

use App\Enums\QuizAttemptStatus;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Support\AnswerChecker;
use Illuminate\Support\Facades\DB;

/**
 * Marks a finished attempt on the server. Unanswered questions score nothing. Running it twice changes nothing.
 */
class MarkQuizAttempt
{
    public function handle(QuizAttempt $attempt): QuizAttempt
    {
        return DB::transaction(function () use ($attempt): QuizAttempt {
            $attempt = QuizAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status !== QuizAttemptStatus::InProgress) {
                return $attempt;
            }

            $items = $attempt->questions()->with('question.options')->get();
            $score = 0;

            foreach ($items as $item) {
                /** @var QuizAttemptQuestion $item */
                $question = $item->question;
                $correct = AnswerChecker::isCorrect($question, $item->response);
                $marks = $correct ? $question->marks : 0;
                $score += $marks;

                $item->update(['is_correct' => $correct, 'marks_awarded' => $marks]);

                DB::table('question_exposures')
                    ->where('user_id', $attempt->user_id)
                    ->where('question_id', $question->id)
                    ->update(['last_correct' => $correct]);

                if ($correct) {
                    Question::withTrashed()->whereKey($question->id)->increment('times_correct');
                }
            }

            $now = now();
            $attempt->update([
                'status' => QuizAttemptStatus::Submitted,
                'submitted_at' => $now,
                'score' => $score,
                'duration_seconds' => (int) $attempt->started_at->diffInSeconds($now, absolute: true),
            ]);

            return $attempt;
        });
    }
}
