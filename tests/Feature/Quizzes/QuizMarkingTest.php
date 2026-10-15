<?php

use App\Actions\MarkQuizAttempt;
use App\Enums\QuizAttemptStatus;
use App\Enums\YearGroup;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->student = User::factory()->student(YearGroup::Year5)->create();
    Subscription::factory()->active()->forStudents($this->student)->create();
    $this->quiz = Quiz::factory()->create(['title' => 'Year 5 mixed']);
});

/**
 * An attempt with one question answered as given.
 */
function answered(Question $question, ?array $response): QuizAttempt
{
    $attempt = QuizAttempt::factory()->create(['user_id' => test()->student->id, 'quiz_id' => test()->quiz->id, 'max_score' => $question->marks, 'started_at' => now()->subMinutes(5)]);
    $attempt->questions()->create(['question_id' => $question->id, 'position' => 1, 'response' => $response, 'answered_at' => $response ? now() : null]);
    DB::table('question_exposures')->insertOrIgnore(['user_id' => test()->student->id, 'question_id' => $question->id, 'times_seen' => 1, 'last_seen_at' => now()]);

    return $attempt;
}

function markedCorrect(Question $question, ?array $response): bool
{
    $attempt = answered($question, $response);
    app(MarkQuizAttempt::class)->handle($attempt);

    return (bool) $attempt->questions()->sole()->is_correct;
}

it('marks multiple choice', function () {
    $question = Question::factory()->published()->create();
    $right = $question->options()->where('is_correct', true)->value('id');
    $wrong = $question->options()->where('is_correct', false)->value('id');

    expect(markedCorrect($question, ['option' => $right]))->toBeTrue()
        ->and(markedCorrect($question, ['option' => $wrong]))->toBeFalse()
        ->and(markedCorrect($question, null))->toBeFalse();
});

it('marks multi-select only when every correct option and nothing else is ticked', function () {
    $question = Question::factory()->published()->multiSelect()->create();
    [$a, $b, $c] = $question->options()->orderBy('sort_order')->pluck('id')->all();

    expect(markedCorrect($question, ['options' => [$b, $a]]))->toBeTrue()
        ->and(markedCorrect($question, ['options' => [$a]]))->toBeFalse()
        ->and(markedCorrect($question, ['options' => [$a, $b, $c]]))->toBeFalse();
});

it('marks numbers the way children type them', function (string $typed, float $answer, float $tolerance, bool $correct) {
    $question = Question::factory()->published()->numeric($answer, $tolerance)->create();

    expect(markedCorrect($question, ['value' => $typed]))->toBe($correct);
})->with([
    'plain' => ['180', 180, 0, true],
    'thousands comma' => ['1,000', 1000, 0, true],
    'spaces and plus' => [' + 42 ', 42, 0, true],
    'decimal' => ['0.375', 0.375, 0, true],
    'leading dot' => ['.5', 0.5, 0, true],
    'fraction' => ['3/4', 0.75, 0, true],
    'within tolerance' => ['3.14', 3.14159, 0.01, true],
    'outside tolerance' => ['3.2', 3.14159, 0.01, false],
    'wrong' => ['181', 180, 0, false],
    'negative' => ['-5', -5, 0, true],
    'nonsense' => ['twelve', 12, 0, false],
    'divide by zero' => ['1/0', 1, 0, false],
]);

it('marks short answers ignoring case, spacing and a full stop', function (string $typed, bool $correct) {
    $question = Question::factory()->published()->shortText(['x = 4', 'Sixty'])->create();

    expect(markedCorrect($question, ['text' => $typed]))->toBe($correct);
})->with([
    'exact' => ['x = 4', true],
    'no spaces' => ['x=4', true],
    'capitals and extra spaces' => ['  X   =  4 ', true],
    'full stop' => ['sixty.', true],
    'wrong' => ['x = 5', false],
]);

it('scores the attempt, records the result and is safe to run twice', function () {
    $right = Question::factory()->published()->numeric(12)->create(['marks' => 2]);
    $wrong = Question::factory()->published()->numeric(12)->create(['marks' => 1]);
    $attempt = QuizAttempt::factory()->create(['user_id' => $this->student->id, 'quiz_id' => $this->quiz->id, 'max_score' => 3, 'started_at' => now()->subMinutes(4)]);
    $attempt->questions()->create(['question_id' => $right->id, 'position' => 1, 'response' => ['value' => '12'], 'answered_at' => now()]);
    $attempt->questions()->create(['question_id' => $wrong->id, 'position' => 2, 'response' => ['value' => '13'], 'answered_at' => now()]);
    foreach ([$right, $wrong] as $question) {
        DB::table('question_exposures')->insert(['user_id' => $this->student->id, 'question_id' => $question->id, 'times_seen' => 1, 'last_seen_at' => now()]);
    }

    app(MarkQuizAttempt::class)->handle($attempt);
    app(MarkQuizAttempt::class)->handle($attempt->fresh());

    expect($attempt->fresh())
        ->status->toBe(QuizAttemptStatus::Submitted)
        ->score->toBe(2)
        ->duration_seconds->toBe(240)
        ->and($attempt->questions()->pluck('marks_awarded')->all())->toBe([2, 0])
        ->and($right->fresh()->times_correct)->toBe(1)
        ->and($wrong->fresh()->times_correct)->toBe(0)
        ->and(DB::table('question_exposures')->where('question_id', $wrong->id)->value('last_correct'))->toBe(0);
});

it('finishes from the player and shows the results with explanations', function () {
    $question = Question::factory()->published()->numeric(0.75)->create(['stem' => 'What is $\frac{3}{4}$ as a decimal?', 'explanation' => 'Divide 3 by 4.']);
    $attempt = answered($question, ['value' => '0.7']);

    $this->actingAs($this->student)->post(route('quiz-attempts.submit', $attempt))->assertRedirect(route('quiz-attempts.show', $attempt));

    $this->actingAs($this->student)->get(route('quiz-attempts.show', $attempt))
        ->assertInertia(fn (Assert $page) => $page
            ->component('quizzes/results')
            ->where('attempt.quizTitle', 'Year 5 mixed')
            ->where('attempt.score', 0)
            ->where('attempt.maxScore', 1)
            ->where('attempt.percentage', 0)
            ->where('questions.0.stem', 'What is $\frac{3}{4}$ as a decimal?')
            ->where('questions.0.yourAnswer', '0.7')
            ->where('questions.0.correctAnswer', '0.75')
            ->where('questions.0.isCorrect', false)
            ->where('questions.0.explanation', 'Divide 3 by 4.'));
});

it('shows choice answers as their text', function () {
    $question = Question::factory()->published()->multiSelect()->create();
    [$a, $b, $c] = $question->options()->orderBy('sort_order')->get()->all();
    $attempt = answered($question, ['options' => [$a->id, $c->id]]);
    app(MarkQuizAttempt::class)->handle($attempt);

    $this->actingAs($this->student)->get(route('quiz-attempts.show', $attempt))
        ->assertInertia(fn (Assert $page) => $page
            ->where('questions.0.yourAnswer', "{$a->body}, {$c->body}")
            ->where('questions.0.correctAnswer', "{$a->body}, {$b->body}"));
});

it('keeps results private and refuses to finish someone else\'s attempt', function () {
    $attempt = answered(Question::factory()->published()->numeric(1)->create(), ['value' => '1']);
    $classmate = User::factory()->student()->create();
    Subscription::factory()->active()->forStudents($classmate)->create();

    $this->actingAs($classmate)->post(route('quiz-attempts.submit', $attempt))->assertForbidden();
    $this->actingAs($classmate)->get(route('quiz-attempts.show', $attempt))->assertForbidden();
    expect($attempt->fresh()->status)->toBe(QuizAttemptStatus::InProgress);
});
