<?php

namespace App\Http\Controllers;

use App\Actions\MarkQuizAttempt;
use App\Actions\StartQuizAttempt;
use App\Enums\QuizAttemptStatus;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Support\AnswerChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
     * Finish the attempt and mark it.
     */
    public function submit(Request $request, QuizAttempt $attempt, MarkQuizAttempt $markQuizAttempt): RedirectResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);

        $markQuizAttempt->handle($attempt);

        return to_route('quiz-attempts.show', $attempt);
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

        $attempt->load(['quiz', 'questions.question.options']);
        $percentage = $attempt->percentage() ?? 0;

        return Inertia::render('quizzes/results', [
            'attempt' => [
                'id' => $attempt->id,
                'quizTitle' => $attempt->quiz->title,
                'quizSlug' => $attempt->quiz->slug,
                'score' => $attempt->score ?? 0,
                'maxScore' => $attempt->max_score,
                'percentage' => $percentage,
                'duration' => $attempt->duration_seconds !== null ? max(1, (int) round($attempt->duration_seconds / 60)).' min' : null,
                'message' => match (true) {
                    $percentage >= 90 => 'Brilliant work!',
                    $percentage >= 70 => 'Great job!',
                    $percentage >= 50 => 'Good effort. Keep practising!',
                    default => 'Keep going. Every quiz helps you learn.',
                },
            ],
            'questions' => $attempt->questions->map(fn (QuizAttemptQuestion $item): array => [
                'position' => $item->position,
                'type' => $item->question->type->value,
                'stem' => $item->question->stem,
                'imageUrl' => $item->question->image_path ? Storage::disk('public')->url($item->question->image_path) : null,
                'yourAnswer' => AnswerChecker::describeResponse($item->question, $item->response),
                'correctAnswer' => AnswerChecker::describeAnswer($item->question),
                'isCorrect' => (bool) $item->is_correct,
                'marks' => $item->question->marks,
                'marksAwarded' => $item->marks_awarded,
                'explanation' => $item->question->explanation,
            ])->values(),
        ]);
    }
}
