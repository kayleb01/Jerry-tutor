<?php

use App\Enums\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the registration page', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/register'));
});

it('registers a guardian and logs them in', function () {
    $this->post(route('register.store'), [
        'name' => 'Ngozi Okafor',
        'email' => 'Ngozi@Example.COM',
        'phone' => '08031234567',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect('/dashboard');

    $user = User::firstWhere('email', 'ngozi@example.com');

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(Role::Guardian)
        ->and($user->phone)->toBe('08031234567');
    $this->assertAuthenticatedAs($user);
});

it('never lets a sign-up choose its own role', function () {
    $this->post(route('register.store'), [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'role' => 'admin',
    ]);

    expect(User::firstWhere('email', 'sneaky@example.com')->role)->toBe(Role::Guardian);
});

it('rejects an email that is already registered, ignoring case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Copycat',
        'email' => 'TAKEN@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');
});

it('requires a name, email and confirmed password', function () {
    $this->post(route('register.store'), [
        'password' => 'a-strong-password',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors(['name', 'email', 'password']);
});
