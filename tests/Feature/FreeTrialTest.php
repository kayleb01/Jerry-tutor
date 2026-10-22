<?php

use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\QuizAccessState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.trial_days' => 30, 'payments.default' => 'paystack', 'payments.paystack.secret_key' => 'sk_test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
    Route::middleware(['web', 'auth', 'quiz.access'])->get('/__quiz-probe', fn () => 'quiz ok');
});

/** Thirty days from the frozen clock falls on 21 Nov 2026. */
function trialEndingIn(int $days): CarbonImmutable
{
    return CarbonImmutable::now()->addDays($days);
}

function studentPayloadFor(string $username): array
{
    return [
        'name' => 'Ava Okafor',
        'username' => $username,
        'email' => '',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'year_group' => 5,
    ];
}

it('gives a student created by an admin thirty days of trial', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.users.store'), ['role' => 'student', ...studentPayloadFor('ava.admin')])
        ->assertRedirect(route('admin.users.index'));

    expect(User::firstWhere('username', 'ava.admin')->trial_ends_at->equalTo(CarbonImmutable::now()->addDays(30)))->toBeTrue();
});

it('gives a student added by a tutor thirty days of trial', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->withSlot($tutor)->create(['year_group' => YearGroup::Year5]);

    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayloadFor('ava.tutor'))->assertRedirect();

    expect(User::firstWhere('username', 'ava.tutor')->trial_ends_at->equalTo(CarbonImmutable::now()->addDays(30)))->toBeTrue();
});

it('gives a child added by a parent thirty days of trial', function () {
    $this->actingAs(User::factory()->guardian()->create())->post(route('children.store'), studentPayloadFor('ava.parent'))->assertRedirect();

    expect(User::firstWhere('username', 'ava.parent')->trial_ends_at->equalTo(CarbonImmutable::now()->addDays(30)))->toBeTrue();
});

it('takes the trial length from config', function () {
    config(['academy.trial_days' => 14]);

    $this->actingAs(User::factory()->guardian()->create())->post(route('children.store'), studentPayloadFor('ava.short'))->assertRedirect();

    expect(User::firstWhere('username', 'ava.short')->trial_ends_at->equalTo(CarbonImmutable::now()->addDays(14)))->toBeTrue();
});

it('lets a student on trial open quiz pages and reports quiz access, until the trial ends', function () {
    $student = User::factory()->student()->onTrial(trialEndingIn(3))->create();

    $this->actingAs($student)->get('/__quiz-probe')->assertSee('quiz ok');
    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('quizAccess', true));

    $this->travelTo(trialEndingIn(3)->addSecond());

    $this->actingAs($student->fresh())->get('/__quiz-probe')->assertInertia(fn (Assert $page) => $page->component('billing/paywall'));
    $this->actingAs($student->fresh())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('quizAccess', false)->where('trial', null));
});

it('leaves an existing student with no trial and no access', function () {
    $student = User::factory()->student()->create();

    expect($student->trial_ends_at)->toBeNull()
        ->and($student->isOnTrial())->toBeFalse()
        ->and($student->trialEndsLabel())->toBeNull();

    $this->actingAs($student)->get('/__quiz-probe')->assertInertia(fn (Assert $page) => $page->component('billing/paywall'));
});

it('does not treat a trial as access for anyone but students', function () {
    expect(User::factory()->guardian()->create()->forceFill(['trial_ends_at' => trialEndingIn(5)])->isOnTrial())->toBeFalse();
});

it('labels the end of the trial in academy time', function () {
    $student = User::factory()->student()->onTrial(CarbonImmutable::parse('2026-11-21 23:30:00', 'UTC'))->create();

    expect($student->trialEndsLabel())->toBe('22 Nov');
});

it('shows a quiet notice with the end date on the student dashboard', function () {
    $student = User::factory()->student()->onTrial(trialEndingIn(30))->create();

    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('trial.message', 'Free trial — ends 21 Nov')
        ->where('trial.action', null));
});

it('counts the days down in the last week and offers to subscribe', function () {
    $student = User::factory()->student()->onTrial(trialEndingIn(3))->create();

    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('trial.message', 'Your free trial ends in 3 days')
        ->where('trial.action', 'subscribe'));
});

it('asks a student with a parent to ask their parent', function () {
    $student = User::factory()->student()->onTrial(trialEndingIn(3))->create();
    User::factory()->guardian()->create()->linkChild($student, 'Mother');

    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('trial.message', 'Your free trial ends in 3 days')
        ->where('trial.action', 'ask-parent'));
});

it('words the last days of the trial', function (int $days, string $message) {
    $student = User::factory()->student()->onTrial(trialEndingIn($days))->create();

    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('trial.message', $message));
})->with([
    'a week' => [7, 'Your free trial ends in 7 days'],
    'eight days is still quiet' => [8, 'Free trial — ends 30 Oct'],
    'one day' => [1, 'Your free trial ends in 1 day'],
]);

it('says the trial ends today on its last day', function () {
    $student = User::factory()->student()->onTrial(CarbonImmutable::now()->addHour())->create();

    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('trial.message', 'Your free trial ends today'));
});

it('says nothing about the trial when a subscription covers the student', function () {
    $student = User::factory()->student()->onTrial(trialEndingIn(3))->create();
    Subscription::factory()->active()->forStudents($student)->create();

    $this->actingAs($student)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('quizAccess', true)->where('trial', null));
});

it('reads a trial child as trial for the parent', function () {
    $parent = User::factory()->guardian()->create();
    $child = User::factory()->student()->onTrial(trialEndingIn(30))->create();
    $parent->linkChild($child, 'Mother');

    expect(app(QuizAccessState::class)->for($child, $parent))->toMatchArray([
        'state' => 'trial', 'note' => 'Free trial · ends 21 Nov', 'pendingOther' => false, 'paymentFailed' => false,
    ]);
});

it('lets a covering subscription beat the trial and a pending checkout beat it too', function () {
    $parent = User::factory()->guardian()->create();
    $covered = User::factory()->student()->onTrial(trialEndingIn(30))->create();
    $waiting = User::factory()->student()->onTrial(trialEndingIn(30))->create();
    $parent->linkChild($covered, 'Mother');
    $parent->linkChild($waiting, 'Mother');
    Subscription::factory()->active()->forStudents($covered)->create(['payer_id' => $parent->id]);
    Subscription::factory()->pending()->forStudents($waiting)->create(['payer_id' => $parent->id]);

    expect(app(QuizAccessState::class)->for($covered, $parent)['state'])->toBe('covered')
        ->and(app(QuizAccessState::class)->for($waiting, $parent)['state'])->toBe('pending');
});

it('shows the trial state on the parent dashboard and billing page', function () {
    $parent = User::factory()->guardian()->create();
    $child = User::factory()->student()->onTrial(trialEndingIn(30))->create();
    $parent->linkChild($child, 'Mother');

    $this->actingAs($parent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('children.0.access.state', 'trial')
        ->where('children.0.access.note', 'Free trial · ends 21 Nov'));

    $this->actingAs($parent)->get(route('billing.index'))->assertInertia(fn (Assert $page) => $page
        ->where('children.0.state', 'trial')
        ->where('children.0.paymentFailed', false));
});

it('lets a parent subscribe a child who is on trial', function () {
    Plan::factory()->create(['paystack_plan_code' => 'PLN_standard', 'max_students' => 1]);
    $plan = Plan::first();
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(paymentFixture('paystack', 'initialize'))]);
    $parent = User::factory()->guardian()->create(['email' => 'ngozi@example.com']);
    $child = User::factory()->student()->onTrial(trialEndingIn(30))->create();
    $parent->linkChild($child, 'Mother');

    $this->actingAs($parent)->post(route('billing.checkout'), ['plan' => $plan->id, 'students' => [$child->id]])
        ->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

    expect(Subscription::where('payer_id', $parent->id)->count())->toBe(1);
});

it('flags only active trials on the admin users list', function () {
    User::factory()->student()->onTrial(trialEndingIn(30))->create(['name' => 'A On Trial']);
    User::factory()->student()->onTrial(CarbonImmutable::now()->subDay())->create(['name' => 'B Expired']);
    User::factory()->student()->create(['name' => 'C Existing']);
    $admin = User::factory()->admin()->create(['name' => 'Z Admin']);

    $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
        ->where('users.data.0.trialEndsLabel', '21 Nov')
        ->where('users.data.1.trialEndsLabel', null)
        ->where('users.data.2.trialEndsLabel', null)
        ->where('users.data.3.trialEndsLabel', null));
});
