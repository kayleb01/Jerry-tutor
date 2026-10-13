<?php

use App\Models\User;

it('stores emails and usernames in lowercase', function () {
    $user = User::factory()->student()->create([
        'email' => '  Mixed@Example.COM ',
        'username' => 'Ava.Okafor',
    ]);

    expect($user->fresh())
        ->email->toBe('mixed@example.com')
        ->username->toBe('ava.okafor');
});

it('logs in with a mixed-case email', function () {
    $user = User::factory()->create(['email' => 'parent@example.com']);

    $this->post(route('login.store'), ['login' => 'Parent@Example.COM', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('stops a deactivated user from logging in', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $user->delete();

    $this->post(route('login.store'), ['login' => 'gone@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('ends the session of a user who is deactivated', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $this->post(route('login.store'), ['login' => 'gone@example.com', 'password' => 'password']);

    $user->delete();
    $this->app['auth']->forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('keeps profile and guardian links when a student is deactivated, and restores them', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();
    $guardian->linkChild($student);

    $student->delete();

    expect($student->fresh()->trashed())->toBeTrue();
    $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id]);
    $this->assertDatabaseHas('guardian_student', ['student_id' => $student->id]);

    $student->restore();

    expect($guardian->children()->pluck('users.id')->all())->toBe([$student->id]);
});
