<?php

use App\Enums\Difficulty;
use App\Enums\KeyStage;
use App\Enums\QuizAttemptStatus;
use App\Enums\YearGroup;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->topic = Topic::factory()->create(['key_stage' => KeyStage::Ks2]);
    $this->quiz = Quiz::factory()->create(['title' => 'Year 5 mixed', 'slug' => 'year-5-mixed', 'key_stage' => KeyStage::Ks2, 'year_group' => YearGroup::Year5, 'difficulty' => Difficulty::Intermediate, 'question_count' => 3]);
    $this->choice = Question::factory()->published()->for($this->topic)->create(['difficulty' => Difficulty::Intermediate, 'stem' => 'Pick 12', 'marks' => 1]);
    $this->number = Question::factory()->published()->numeric(0.75)->for($this->topic)->create(['difficulty' => Difficulty::Intermediate, 'marks' => 2, 'explanation' => 'SECRET_EXPLANATION']);
    $this->words = Question::factory()->published()->shortText(['x = 4'])->for($this->topic)->create(['difficulty' => Difficulty::Intermediate]);
    $this->student = User::factory()->student(YearGroup::Year5)->create();
    Subscription::factory()->active()->forStudents($this->student)->create();
});

function startQuiz(User $student, Quiz $quiz)
{
    return test()->actingAs($student)->post(route('quizzes.attempts.store', $quiz));
}

it('starts an attempt with questions from the bank and opens the first one', function () {
    startQuiz($this->student, $this->quiz)->assertRedirect(route('quiz-attempts.questions.show', [QuizAttempt::sole(), 1]));

    $attempt = QuizAttempt::sole();

    expect($attempt)->status->toBe(QuizAttemptStatus::InProgress)->max_score->toBe(4)->user_id->toBe($this->student->id)
        ->and($attempt->questions->pluck('position')->all())->toBe([1, 2, 3])
        ->and(DB::table('question_exposures')->where('user_id', $this->student->id)->count())->toBe(3)
        ->and($this->choice->fresh()->times_served)->toBe(1);
});

it('picks up where the student left off instead of starting again', function () {
    startQuiz($this->student, $this->quiz);
    $attempt = QuizAttempt::sole();
    $attempt->questions()->where('position', 1)->update(['response' => json_encode(['value' => '1']), 'answered_at' => now()]);

    startQuiz($this->student, $this->quiz)->assertRedirect(route('quiz-attempts.questions.show', [$attempt, 2]));

    expect(QuizAttempt::count())->toBe(1);
});

it('says so when a quiz has no questions yet', function () {
    $empty = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'year_group' => YearGroup::Year5, 'difficulty' => Difficulty::Challenging]);

    startQuiz($this->student, $empty)->assertRedirect()->assertInertiaFlash('toast.message', "This quiz doesn't have any questions yet.");
    expect(QuizAttempt::count())->toBe(0);
});

it('shows one question without the answer key', function () {
    startQuiz($this->student, $this->quiz);
    $attempt = QuizAttempt::sole();
    $position = $attempt->questions->firstWhere('question_id', $this->choice->id)->position;

    $response = $this->actingAs($this->student)->get(route('quiz-attempts.questions.show', [$attempt, $position]));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('quizzes/play')
        ->where('attempt.quizTitle', 'Year 5 mixed')
        ->where('attempt.total', 3)
        ->where('attempt.position', $position)
        ->where('question.type', 'multiple_choice')
        ->where('question.stem', 'Pick 12')
        ->has('question.options', 4)
        ->where('question.options.0', fn ($option) => array_keys(collect($option)->all()) === ['id', 'body']));

    $json = json_encode($response->viewData('page')['props']);
    expect($json)->not->toContain('is_correct')->not->toContain('isCorrect')->not->toContain('accepted')->not->toContain('SECRET_EXPLANATION')->not->toContain('0.75');
});

it('saves answers of each type and moves on', function () {
    startQuiz($this->student, $this->quiz);
    $attempt = QuizAttempt::sole();
    $positionOf = fn (Question $question) => $attempt->questions->firstWhere('question_id', $question->id)->position;
    $correct = $this->choice->options()->where('is_correct', true)->value('id');

    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, $positionOf($this->choice)]), ['option' => $correct, 'stay' => true])
        ->assertRedirect(route('quiz-attempts.questions.show', [$attempt, $positionOf($this->choice)]));
    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, $positionOf($this->number)]), ['value' => '0.75']);
    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, $positionOf($this->words)]), ['text' => 'x=4']);

    $saved = $attempt->questions()->get()->keyBy('question_id');

    expect($saved[$this->choice->id]->response)->toBe(['option' => $correct])
        ->and($saved[$this->number->id]->response)->toBe(['value' => '0.75'])
        ->and($saved[$this->words->id]->response)->toBe(['text' => 'x=4'])
        ->and($saved[$this->words->id]->answered_at)->not->toBeNull();
});

it('refuses answers that do not fit the question', function () {
    startQuiz($this->student, $this->quiz);
    $attempt = QuizAttempt::sole();
    $choicePosition = $attempt->questions->firstWhere('question_id', $this->choice->id)->position;
    $otherQuestionsOption = Question::factory()->published()->create()->options()->value('id');

    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, $choicePosition]), ['option' => $otherQuestionsOption])
        ->assertSessionHasErrors(['option']);
    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, $choicePosition]), ['value' => '12'])
        ->assertSessionHasErrors(['option']);

    expect($attempt->questions()->whereNotNull('response')->count())->toBe(0);
});

it('asks again when a number answer cannot be read', function () {
    startQuiz($this->student, $this->quiz);
    $attempt = QuizAttempt::sole();
    $position = $attempt->questions->firstWhere('question_id', $this->number->id)->position;

    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, $position]), ['value' => 'about 3'])
        ->assertSessionHasErrors(['value']);

    expect($attempt->questions()->whereNotNull('response')->count())->toBe(0);
});

it('mixes up the order of choices for each attempt, and keeps it while the student plays', function () {
    $orders = collect(range(1, 8))->map(function () {
        $student = User::factory()->student(YearGroup::Year5)->create();
        Subscription::factory()->active()->forStudents($student)->create();
        startQuiz($student, $this->quiz);
        $attempt = QuizAttempt::where('user_id', $student->id)->sole();
        $position = $attempt->questions->firstWhere('question_id', $this->choice->id)->position;
        $order = fn () => collect($this->actingAs($student)->get(route('quiz-attempts.questions.show', [$attempt, $position]))->viewData('page')['props']['question']['options'])->pluck('id')->all();
        $first = $order();

        expect($order())->toBe($first);

        return implode(',', $first);
    });

    expect($orders->unique()->count())->toBeGreaterThan(1);
});

it('keeps attempts private and closed once finished', function () {
    startQuiz($this->student, $this->quiz);
    $attempt = QuizAttempt::sole();
    $classmate = User::factory()->student(YearGroup::Year5)->create();
    Subscription::factory()->active()->forStudents($classmate)->create();

    $this->actingAs($classmate)->get(route('quiz-attempts.questions.show', [$attempt, 1]))->assertForbidden();
    $this->actingAs($classmate)->put(route('quiz-attempts.questions.update', [$attempt, 1]), ['value' => '1'])->assertForbidden();

    $attempt->update(['status' => QuizAttemptStatus::Submitted, 'score' => 0, 'submitted_at' => now()]);

    $this->actingAs($this->student)->get(route('quiz-attempts.questions.show', [$attempt, 1]))->assertRedirect(route('quiz-attempts.show', $attempt));
    $this->actingAs($this->student)->put(route('quiz-attempts.questions.update', [$attempt, 1]), ['value' => '1'])->assertRedirect(route('quiz-attempts.show', $attempt));
    $this->actingAs($this->student)->get(route('quiz-attempts.questions.show', [$attempt, 9]))->assertRedirect(route('quiz-attempts.show', $attempt));
});

it('needs quiz access', function () {
    $unpaid = User::factory()->student(YearGroup::Year5)->create();

    startQuiz($unpaid, $this->quiz)->assertInertia(fn (Assert $page) => $page->component('billing/paywall'));
    expect(QuizAttempt::count())->toBe(0);
});
