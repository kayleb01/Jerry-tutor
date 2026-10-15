<?php

use App\Enums\Difficulty;
use App\Enums\KeyStage;
use App\Enums\YearGroup;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\QuizCatalogueSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->seed([CurriculumSeeder::class, QuizCatalogueSeeder::class]);
});

function subscribedStudent(YearGroup $year): User
{
    $student = User::factory()->student($year)->create();
    Subscription::factory()->active()->forStudents($student)->create();

    return $student;
}

it('shows a Year 5 student their year quizzes and exam practice', function () {
    $student = subscribedStudent(YearGroup::Year5);
    $fractions = Topic::where('key_stage', KeyStage::Ks2)->where('slug', 'fractions')->sole();
    Question::factory()->count(2)->published()->for($fractions)->create(['difficulty' => Difficulty::Intermediate]);
    $numberQuiz = Quiz::firstWhere('slug', 'year-5-number');
    QuizAttempt::factory()->submitted(6)->create(['user_id' => $student->id, 'quiz_id' => $numberQuiz->id, 'submitted_at' => now()->subDays(2)]);
    QuizAttempt::factory()->submitted(9)->create(['user_id' => $student->id, 'quiz_id' => $numberQuiz->id, 'submitted_at' => now()->subDay()]);
    QuizAttempt::factory()->create(['user_id' => $student->id, 'quiz_id' => Quiz::firstWhere('slug', 'year-5-mixed')->id]);

    $this->actingAs($student)->get(route('quizzes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quizzes/index')
            ->where('yearGroup', 'Year 5')
            ->has('sections', 2)
            ->where('sections.0.title', 'Year 5 quizzes')
            ->has('sections.0.quizzes', 5)
            ->where('sections.0.quizzes.0.slug', 'year-5-mixed')
            ->where('sections.0.quizzes.0.inProgress', true)
            ->where('sections.0.quizzes.1', [
                'slug' => 'year-5-number',
                'title' => 'Year 5 Number',
                'strand' => 'number',
                'available' => 2,
                'lastScore' => '9/10',
                'bestPercentage' => 90,
                'inProgress' => false,
            ])
            ->where('sections.0.quizzes.2.available', 0)
            ->where('sections.1.title', 'Exam practice')
            ->where('sections.1.quizzes.0.slug', '11-practice')
            ->has('recent', 2)
            ->where('recent.0.title', 'Year 5 Number')
            ->where('recent.0.score', '9/10'));
});

it('locks the KS3 Challenge until three strong scores at the student\'s level', function () {
    $student = subscribedStudent(YearGroup::Year8);
    $year8 = Quiz::firstWhere('slug', 'year-8-mixed');
    QuizAttempt::factory()->submitted(8)->create(['user_id' => $student->id, 'quiz_id' => $year8->id]);
    QuizAttempt::factory()->submitted(7)->create(['user_id' => $student->id, 'quiz_id' => $year8->id]);

    $this->actingAs($student)->get(route('quizzes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.0.title', 'Year 8 quizzes')
            ->where('sections.1.key', 'challenge')
            ->where('sections.1.locked', true)
            ->where('sections.1.progress', ['done' => 1, 'needed' => 3])
            ->has('sections.1.quizzes', 0));

    QuizAttempt::factory()->count(2)->submitted(10)->create(['user_id' => $student->id, 'quiz_id' => $year8->id]);

    $this->actingAs($student)->get(route('quizzes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.1.locked', false)
            ->has('sections.1.quizzes', 5)
            ->where('sections.1.quizzes.0.slug', 'ks3-challenge-mixed'));
});

it('gives GCSE years the GCSE quizzes', function () {
    $this->actingAs(subscribedStudent(YearGroup::Year10))->get(route('quizzes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sections', 1)
            ->where('sections.0.title', 'GCSE')
            ->where('sections.0.quizzes.0.slug', 'gcse-mixed'));
});

it('shows the paywall without a subscription', function () {
    $this->actingAs(User::factory()->student(YearGroup::Year5)->create())->get(route('quizzes.index'))
        ->assertInertia(fn (Assert $page) => $page->component('billing/paywall'));
});

it('links the dashboard strand cards to the student\'s quizzes', function () {
    $student = subscribedStudent(YearGroup::Year5);
    QuizAttempt::factory()->submitted(7)->create(['user_id' => $student->id, 'quiz_id' => Quiz::firstWhere('slug', 'year-5-algebra')->id]);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('strands.1.key', 'algebra')
            ->where('strands.1.quizSlug', 'year-5-algebra')
            ->where('strands.1.lastScore', '7/10')
            ->where('strands.0.lastScore', null)
            ->where('quizAccess', true));
});

it('refuses to start a quiz outside the student\'s catalogue', function () {
    $student = subscribedStudent(YearGroup::Year5);

    $this->actingAs($student)->post(route('quizzes.attempts.store', 'gcse-mixed'))->assertForbidden();
    $this->actingAs($student)->post(route('quizzes.attempts.store', 'year-7-mixed'))->assertForbidden();
    expect(QuizAttempt::count())->toBe(0);
});

it('refuses the Challenge tier until it is unlocked', function () {
    $student = subscribedStudent(YearGroup::Year7);
    $challenge = Quiz::where('slug', 'like', 'ks3-challenge%')->orderBy('sort_order')->firstOrFail();

    $this->actingAs($student)->post(route('quizzes.attempts.store', $challenge->slug))->assertForbidden();

    QuizAttempt::factory()->count(3)->submitted(9)->create(['user_id' => $student->id, 'quiz_id' => Quiz::firstWhere('slug', 'year-7-mixed')->id]);

    $this->actingAs($student)->post(route('quizzes.attempts.store', $challenge->slug))->assertRedirect();
});
