<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'quiz.access'])->get('/__quiz-probe', fn () => 'quiz ok');
});

it('lets staff and students with access through', function () {
    $student = User::factory()->student()->create();
    Subscription::factory()->active()->forStudents($student)->create();

    $this->actingAs($student)->get('/__quiz-probe')->assertOk()->assertSee('quiz ok');
    $this->actingAs(User::factory()->tutor()->create())->get('/__quiz-probe')->assertSee('quiz ok');
    $this->actingAs(User::factory()->admin()->create())->get('/__quiz-probe')->assertSee('quiz ok');
});

it('lets a student without access subscribe, or ask their parent', function () {
    $student = User::factory()->student()->create(['name' => 'Ada Okafor']);
    User::factory()->guardian()->create(['name' => 'Ngozi Okafor'])->linkChild($student, 'Mother');

    $this->actingAs($student)->get('/__quiz-probe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/paywall')
            ->where('guardians', ['Ngozi Okafor'])
            ->where('canPay', true));
});

it('lets a student with no parent account pay for themselves', function () {
    $this->actingAs(User::factory()->student()->create())->get('/__quiz-probe')
        ->assertInertia(fn (Assert $page) => $page->component('billing/paywall')->where('guardians', [])->where('canPay', true));
});

it('keeps parents out of the quiz pages', function () {
    $this->actingAs(User::factory()->guardian()->create())->get('/__quiz-probe')->assertForbidden();
});
