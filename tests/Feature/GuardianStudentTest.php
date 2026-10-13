<?php

use App\Models\User;

it('links a guardian to a child both ways', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();

    $guardian->linkChild($student, 'Mother');

    expect($guardian->children->pluck('id')->all())->toBe([$student->id])
        ->and($student->guardians->pluck('id')->all())->toBe([$guardian->id])
        ->and($guardian->children->first()->pivot->relationship)->toBe('Mother');
});

it('ignores linking the same child twice', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();

    $guardian->linkChild($student);
    $guardian->linkChild($student);

    expect($guardian->children()->count())->toBe(1);
});

it('refuses links between the wrong roles', function (string $guardianState, string $childState) {
    $guardian = User::factory()->{$guardianState}()->create();
    $child = User::factory()->{$childState}()->create();

    $guardian->linkChild($child);
})->with([
    'tutor as guardian' => ['tutor', 'student'],
    'guardian as child' => ['guardian', 'guardian'],
    'admin as child' => ['guardian', 'admin'],
])->throws(InvalidArgumentException::class);

it('refuses linking a user to themselves', function () {
    $guardian = User::factory()->guardian()->create();

    $guardian->linkChild($guardian);
})->throws(InvalidArgumentException::class);

it('removes links when the student is permanently deleted', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();
    $guardian->linkChild($student);

    $student->forceDelete();

    $this->assertDatabaseCount('guardian_student', 0);
});
