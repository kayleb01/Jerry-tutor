<?php

use App\Enums\LessonStatus;
use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('belongs to a tutor and describes its schedule', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->for($tutor, 'tutor')->create([
        'weekday' => Weekday::Saturday,
        'start_time' => '10:00',
        'duration_minutes' => 90,
    ]);

    expect($group->tutor->is($tutor))->toBeTrue()
        ->and($group->hasSchedule())->toBeTrue()
        ->and($group->scheduleLabel())->toBe('Saturdays at 10:00 · 90 min')
        ->and($tutor->classGroupsTaught()->first()->is($group))->toBeTrue();
});

it('has no schedule label without a weekday and time', function () {
    $group = ClassGroup::factory()->create(['weekday' => null, 'start_time' => null]);

    expect($group->hasSchedule())->toBeFalse()
        ->and($group->scheduleLabel())->toBe('No regular schedule');
});

it('enrols, withdraws and re-enrols a student, keeping one row', function () {
    $group = ClassGroup::factory()->create();
    $student = User::factory()->student()->create();

    $group->enrol($student);
    expect($group->activeStudents()->pluck('users.id')->all())->toBe([$student->id]);

    $group->withdraw($student);
    expect($group->activeStudents()->count())->toBe(0)
        ->and($group->students()->count())->toBe(1);

    $group->enrol($student);
    expect($group->activeStudents()->count())->toBe(1);
    $this->assertDatabaseCount('class_group_student', 1);
    expect($student->enrolledClassGroups()->first()->is($group))->toBeTrue();
});

it('refuses to enrol someone who is not a student', function (string $state) {
    ClassGroup::factory()->create()->enrol(User::factory()->{$state}()->create());
})->with(['guardian', 'tutor', 'admin'])->throws(InvalidArgumentException::class);

it('stores lessons against a class and tutor', function () {
    $lesson = Lesson::factory()->create();

    expect($lesson->status)->toBe(LessonStatus::Scheduled)
        ->and($lesson->classGroup->lessons()->first()->is($lesson))->toBeTrue()
        ->and($lesson->tutor->lessonsTaught()->first()->is($lesson))->toBeTrue()
        ->and($lesson->ends_at->greaterThan($lesson->starts_at))->toBeTrue();
});

it('allows only one lesson per class at a given start time', function () {
    $lesson = Lesson::factory()->create();

    Lesson::factory()->for($lesson->classGroup)->create(['starts_at' => $lesson->starts_at]);
})->throws(UniqueConstraintViolationException::class);
