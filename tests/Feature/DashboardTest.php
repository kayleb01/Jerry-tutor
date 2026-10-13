<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows each role its own dashboard', function (string $state, string $component) {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'admin' => ['admin', 'dashboard/admin'],
    'tutor' => ['tutor', 'dashboard/tutor'],
    'student' => ['student', 'dashboard/student'],
    'guardian' => ['guardian', 'dashboard/guardian'],
]);

it('makes unverified guardians verify first', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

it('lets a student without an email straight in', function () {
    $student = User::factory()->student()->unverified()->create(['email' => null]);

    $this->actingAs($student)->get(route('dashboard'))->assertOk();
});

it('shares only safe user fields with the page', function () {
    $student = User::factory()->student()->create(['username' => 'ava.okafor']);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $student->id)
            ->where('auth.user.username', 'ava.okafor')
            ->where('auth.user.role', 'student')
            ->has('auth.user', 5)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token'));
});

it('redirects signed-in users away from the login and register pages', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertRedirect('/dashboard');
})->with(['login', 'register']);
