<?php

namespace App\Http\Controllers;

use App\Enums\QuestionType;
use App\Enums\QuizAttemptStatus;
use App\Models\QuestionOption;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Support\AnswerChecker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One question per screen. Nothing here reveals the answer key: options carry only id and body.
 */
class QuizPlayerController extends Controller
{
    public function show(Request $request, QuizAttempt $attempt, int $position): Response|RedirectResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);

        $item = $attempt->status === QuizAttemptStatus::InProgress ? $this->item($attempt, $position) : null;

        if ($item === null) {
            return to_route('quiz-attempts.show', $attempt);
        }

        $attempt->load(['quiz', 'questions']);
        $question = $item->question->load('options');

        return Inertia::render('quizzes/play', [
            'attempt' => [
                'id' => $attempt->id,
                'quizTitle' => $attempt->quiz->title,
                'position' => $position,
                'total' => $attempt->questions->count(),
                'answered' => $attempt->questions->whereNotNull('answered_at')->pluck('position')->values()->all(),
            ],
            'question' => [
                'type' => $question->type->value,
                'stem' => $question->stem,
                'imageUrl' => $question->image_path ? Storage::disk('public')->url($question->image_path) : null,
                'marks' => $question->marks,
                'options' => $question->type->hasOptions()
                    ? $this->shuffled($question->options, $item)->map(fn (QuestionOption $option): array => ['id' => $option->id, 'body' => $option->body])->values()->all()
                    : [],
                'response' => $item->response,
            ],
        ]);
    }

    public function update(Request $request, QuizAttempt $attempt, int $position): RedirectResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);

        $item = $attempt->status === QuizAttemptStatus::InProgress ? $this->item($attempt, $position) : null;

        if ($item === null) {
            return to_route('quiz-attempts.show', $attempt);
        }

        $question = $item->question;
        $optionIds = $question->options()->pluck('id')->all();

        $response = match ($question->type) {
            QuestionType::MultipleChoice => ['option' => (int) $request->validate([
                'option' => ['required', 'integer', Rule::in($optionIds)],
            ], ['option.*' => 'Choose one of the answers.'])['option']],
            QuestionType::MultiSelect => ['options' => array_values(array_map('intval', $request->validate([
                'options' => ['required', 'array', 'min:1'],
                'options.*' => ['integer', 'distinct', Rule::in($optionIds)],
            ], ['options.*' => 'Tick at least one answer.', 'options.*.*' => 'Tick at least one answer.'])['options']))],
            QuestionType::Numeric => ['value' => trim($request->validate([
                'value' => ['required', 'string', 'max:30', function (string $attribute, string $value, \Closure $fail): void {
                    if (AnswerChecker::parseNumber($value) === null) {
                        $fail('Type just the number, like 12, 0.5 or 3/4.');
                    }
                }],
            ], ['value.required' => 'Type a number.', 'value.string' => 'Type a number.', 'value.max' => 'Type a number.'])['value'])],
            QuestionType::ShortText => ['text' => trim($request->validate([
                'text' => ['required', 'string', 'max:100'],
            ], ['text.*' => 'Type your answer.'])['text'])],
        };

        $item->update(['response' => $response, 'answered_at' => now()]);

        $total = $attempt->questions()->count();
        $next = $request->boolean('stay') || $position >= $total ? $position : $position + 1;

        return to_route('quiz-attempts.questions.show', [$attempt, $next]);
    }

    /**
     * The choices in an order fixed for this attempt question, so the right answer isn't always "A"
     * and the order doesn't jump about when the student comes back to the question.
     *
     * @param  Collection<int, QuestionOption>  $options
     * @return Collection<int, QuestionOption>
     */
    private function shuffled(Collection $options, QuizAttemptQuestion $item): Collection
    {
        return $options->sortBy(fn (QuestionOption $option): int => crc32("{$item->id}:{$option->id}"));
    }

    private function item(QuizAttempt $attempt, int $position): ?QuizAttemptQuestion
    {
        return $attempt->questions()->where('position', $position)->with('question')->first();
    }
}
