<?php

namespace App\Http\Controllers;

use App\Actions\StartQuizAttempt;
use App\Enums\QuizAttemptStatus;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuizAttemptController extends Controller
{
    /**
     * Start (or resume) a quiz and open the first question still to answer.
     */
    public function store(Request $request, Quiz $quiz, StartQuizAttempt $startQuizAttempt): RedirectResponse
    {
        abort_unless($quiz->is_published, 404);

        $attempt = $startQuizAttempt->handle($quiz, $request->user());

        if ($attempt === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "This quiz doesn't have any questions yet."]);

            return back();
        }

        return to_route('quiz-attempts.questions.show', [$attempt, $this->firstUnanswered($attempt)]);
    }

    private function firstUnanswered(QuizAttempt $attempt): int
    {
        return (int) ($attempt->questions()->whereNull('answered_at')->min('position') ?? $attempt->questions()->max('position') ?? 1);
    }

    /**
     * The results of a finished attempt; an unfinished one goes back to its next question.
     */
    public function show(Request $request, QuizAttempt $attempt): RedirectResponse|Response
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);

        if ($attempt->status === QuizAttemptStatus::InProgress) {
            return to_route('quiz-attempts.questions.show', [$attempt, $this->firstUnanswered($attempt)]);
        }

        return Inertia::render('quizzes/results', ['attempt' => ['id' => $attempt->id]]);
    }
}
