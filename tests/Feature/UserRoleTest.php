<?php

use App\Enums\Role;
use App\Models\User;

it('defaults new users to the guardian role', function () {
    $user = User::factory()->create();

    expect($user->fresh()->role)->toBe(Role::Guardian);
});

it('casts the role column to the Role enum', function () {
    $user = User::factory()->tutor()->create();

    expect($user->fresh()->role)->toBe(Role::Tutor);
});

it('checks a user against one or more roles', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasRole(Role::Admin))->toBeTrue()
        ->and($admin->hasRole(Role::Tutor, Role::Admin))->toBeTrue()
        ->and($admin->hasRole(Role::Student))->toBeFalse();
});

it('ignores a mass-assigned role so users cannot promote themselves', function () {
    $user = User::create([
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'role' => Role::Admin->value,
    ]);

    expect($user->fresh()->role)->toBe(Role::Guardian);
});
