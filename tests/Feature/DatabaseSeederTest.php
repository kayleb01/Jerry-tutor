<?php

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\User;

it('seeds one demo account per role with linked children', function () {
    $this->seed();

    expect(User::firstWhere('email', 'admin@academy.test')->role)->toBe(Role::Admin)
        ->and(User::firstWhere('email', 'tutor@academy.test')->role)->toBe(Role::Tutor);

    $parent = User::firstWhere('email', 'parent@academy.test');

    expect($parent->children->pluck('studentProfile.year_group')->all())
        ->toEqualCanonicalizing([YearGroup::Year5, YearGroup::Year8]);
});

it('can run twice without failing', function () {
    $this->seed();
    $this->seed();

    expect(User::where('email', 'parent@academy.test')->count())->toBe(1);
});

it('seeds students who can log in with a known username', function () {
    $this->seed();

    $this->post(route('login.store'), ['login' => 'ava.year5', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    expect(User::firstWhere('username', 'ava.year5')->email)->toBeNull()
        ->and(User::firstWhere('username', 'leo.year8'))->not->toBeNull();
});

it('seeds a demo class with an enrolled student and upcoming lessons', function () {
    $this->seed();

    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');

    expect($group->tutor->email)->toBe('tutor@academy.test')
        ->and($group->activeStudents()->pluck('username')->all())->toBe(['ava.year5'])
        ->and($group->lessons()->count())->toBeGreaterThan(0);
});
