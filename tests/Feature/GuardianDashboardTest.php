<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\KeyStage;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\Plan;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subscription;
use App\Models\Topic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CurriculumSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->seed(CurriculumSeeder::class);
    $this->parent = User::factory()->guardian()->create(['name' => 'Ngozi Okafor']);
    $this->ada = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ada Okafor']);
    $this->obi = User::factory()->student(YearGroup::Year7)->create(['name' => 'Obi Okafor']);
    $this->parent->linkChild($this->ada, 'Mother');
    $this->parent->linkChild($this->obi, 'Mother');
});

/**
 * A submitted quiz for the student, with one attempt question per entry of [strand slug => correct?].
 *
 * @param  array<int, array{0: string, 1: bool}>  $answers
 */
function finishedQuiz(User $student, string $title, array $answers, CarbonImmutable $at): QuizAttempt
{
    $quiz = Quiz::factory()->create(['title' => $title]);
    $correct = collect($answers)->filter(fn (array $answer) => $answer[1])->count();
    $attempt = QuizAttempt::factory()->submitted($correct, count($answers))->create(['user_id' => $student->id, 'quiz_id' => $quiz->id, 'submitted_at' => $at]);

    foreach (array_values($answers) as $index => [$strand, $isCorrect]) {
        $topic = Topic::where('key_stage', KeyStage::Ks2)->whereHas('strand', fn ($query) => $query->where('slug', $strand))->firstOrFail();
        $attempt->questions()->create([
            'question_id' => Question::factory()->published()->for($topic)->create()->id,
            'position' => $index + 1,
            'is_correct' => $isCorrect,
            'marks_awarded' => $isCorrect ? 1 : 0,
            'answered_at' => $at,
        ]);
    }

    return $attempt;
}

it('shows each child with their quiz access, attendance, next class and quiz progress', function () {
    Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => Plan::factory()->create(['name' => 'Monthly'])->id]);
    $group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $group->enrol($this->ada);
    $lesson = fn (string $start, array $extra = []) => Lesson::factory()->for($group)->create(['starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour(), ...$extra]);
    app(RecordAttendance::class)->handle($lesson('2026-10-10 09:00:00'), $this->ada, AttendanceStatus::Present, null, CarbonImmutable::parse('2026-10-10 09:05:00', 'UTC'));
    app(RecordAttendance::class)->handle($lesson('2026-10-12 09:00:00'), $this->ada, AttendanceStatus::Present, null, CarbonImmutable::parse('2026-10-12 08:55:00', 'UTC'));
    $lesson('2026-10-16 09:00:00', ['status' => LessonStatus::Cancelled]);
    $lesson('2026-10-17 09:00:00');

    finishedQuiz($this->ada, 'Year 5 Number', [['number', true], ['number', true], ['number', false], ['geometry', true]], CarbonImmutable::parse('2026-10-14 16:00:00', 'UTC'));
    finishedQuiz($this->ada, 'Year 5 mixed', [['algebra', false], ['geometry', true]], CarbonImmutable::parse('2026-09-01 16:00:00', 'UTC'));
    QuizAttempt::factory()->create(['user_id' => $this->ada->id]);

    $this->actingAs($this->parent)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/guardian')
            ->has('children', 2)
            ->where('children.0.id', $this->ada->id)
            ->where('children.0.name', 'Ada Okafor')
            ->where('children.0.yearGroup', 'Year 5')
            ->where('children.0.access', ['state' => 'covered', 'note' => 'Monthly · renews 15 Nov', 'paymentFailed' => false])
            ->where('children.0.attendance', ['attendance' => 100, 'onTime' => 50, 'late' => 1, 'absent' => 0])
            ->where('children.0.nextLesson', ['className' => 'Year 5 Maths', 'when' => 'Sat 17 Oct, 10:00'])
            ->where('children.0.quizzes.thisMonth', 1)
            ->where('children.0.quizzes.averagePercentage', 63)
            ->where('children.0.quizzes.strands', [
                ['key' => 'number', 'name' => 'Number', 'correct' => 2, 'total' => 3, 'percentage' => 67],
                ['key' => 'algebra', 'name' => 'Algebra', 'correct' => 0, 'total' => 1, 'percentage' => 0],
                ['key' => 'statistics', 'name' => 'Statistics', 'correct' => 0, 'total' => 0, 'percentage' => null],
                ['key' => 'geometry', 'name' => 'Geometry', 'correct' => 2, 'total' => 2, 'percentage' => 100],
            ])
            ->has('children.0.quizzes.recent', 2)
            ->where('children.0.quizzes.recent.0', fn ($recent) => $recent['title'] === 'Year 5 Number' && $recent['score'] === '3/4' && $recent['percentage'] === 75 && $recent['date'] === 'Wed 14 Oct')
            ->where('children.1.name', 'Obi Okafor')
            ->where('children.1.access', ['state' => 'none', 'note' => 'No quiz access yet', 'paymentFailed' => false])
            ->where('children.1.nextLesson', null)
            ->where('children.1.attendance.attendance', null)
            ->where('children.1.quizzes.recent', [])
            ->where('children.1.quizzes.averagePercentage', null));
});

it('never shows another family\'s child', function () {
    $stranger = User::factory()->student()->create(['name' => 'Someone Else']);
    User::factory()->guardian()->create()->linkChild($stranger);
    finishedQuiz($stranger, 'Their quiz', [['number', true]], now());

    $this->actingAs($this->parent)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('children', 2)->where('children', fn ($children) => collect($children)->pluck('name')->doesntContain('Someone Else')));
});

it('invites a parent with no children to add them', function () {
    $this->actingAs(User::factory()->guardian()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/guardian')->where('children', []));
});

it('offers subscribing only once a plan is linked to the payment gateway', function () {
    config(['payments.default' => 'paystack']);
    $plan = Plan::factory()->create(['paystack_plan_code' => null]);

    $this->actingAs($this->parent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('paymentsAvailable', false));

    $plan->update(['paystack_plan_code' => 'PLN_standard']);

    $this->actingAs($this->parent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('paymentsAvailable', true));
});
