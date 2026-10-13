<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canResetPassword', true)
            ->where('canRegister', true)
            ->where('status', null));
});

it('logs a guardian in with their email', function () {
    $guardian = User::factory()->guardian()->create(['email' => 'parent@example.com']);

    $this->post(route('login.store'), ['login' => 'parent@example.com', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($guardian);
});

it('logs a student in with their username, ignoring case', function () {
    $student = User::factory()->student()->create(['email' => null, 'username' => 'ava.okafor']);

    $this->post(route('login.store'), ['login' => 'Ava.Okafor', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($student);
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'parent@example.com']);

    $this->from(route('login'))
        ->post(route('login.store'), ['login' => 'parent@example.com', 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('rejects an unknown username', function () {
    $this->post(route('login.store'), ['login' => 'nobody', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('shows a friendly error on the form after too many failed attempts', function () {
    User::factory()->create(['email' => 'parent@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['login' => 'parent@example.com', 'password' => 'wrong-password']);
    }

    $this->from(route('login'))
        ->post(route('login.store'), ['login' => 'parent@example.com', 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');
});
