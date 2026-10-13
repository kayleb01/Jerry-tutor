<?php

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates a student with a profile for the given year group', function () {
    $student = User::factory()->student(YearGroup::Year7)->create();

    expect($student->role)->toBe(Role::Student)
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year7);
});

it('grants the challenging tier only to unlocked KS3 students', function (YearGroup $yearGroup, bool $unlocked, bool $expected) {
    $profile = StudentProfile::factory()->create([
        'year_group' => $yearGroup,
        'challenging_tier_unlocked' => $unlocked,
    ]);

    expect($profile->hasChallengingTier())->toBe($expected);
})->with([
    'year 8 unlocked' => [YearGroup::Year8, true, true],
    'year 9 locked' => [YearGroup::Year9, false, false],
    'year 5 unlocked (KS2)' => [YearGroup::Year5, true, false],
    'year 10 unlocked (KS4)' => [YearGroup::Year10, true, false],
]);

it('deletes the profile when the student is deleted', function () {
    $student = User::factory()->student()->create();

    $student->delete();

    expect(StudentProfile::count())->toBe(0);
});

it('allows only one profile per user', function () {
    $student = User::factory()->student()->create();

    StudentProfile::factory()->for($student)->create();
})->throws(UniqueConstraintViolationException::class);
