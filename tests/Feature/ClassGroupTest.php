<?php

use App\Enums\LessonStatus;
use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('has a timetable of slots, each with its own subject and tutor', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->withSlot($tutor, ['weekday' => Weekday::Saturday, 'start_time' => '10:00', 'duration_minutes' => 90])->create();
    $slot = $group->slots()->sole();

    expect($slot->tutor->is($tutor))->toBeTrue()
        ->and($slot->subject->name)->toBe('Maths')
        ->and($slot->hasSchedule())->toBeTrue()
        ->and($slot->timeLabel())->toBe('Sat 10:00–11:30')
        ->and($tutor->classSlotsTaught()->sole()->is($slot))->toBeTrue();
});

it('counts a class as taught by a tutor while they teach one of its slots', function () {
    $tutor = User::factory()->tutor()->create();
    $taught = ClassGroup::factory()->withSlot($tutor)->create();
    $removed = ClassGroup::factory()->withSlot($tutor)->create();
    $removed->slots()->sole()->delete();
    ClassGroup::factory()->withSlot()->create();

    expect(ClassGroup::query()->taughtBy($tutor)->pluck('id')->all())->toBe([$taught->id])
        ->and($removed->slots()->count())->toBe(0);
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

it('allows only one lesson per slot at a given start time', function () {
    $lesson = Lesson::factory()->create();

    Lesson::factory()->create(['class_slot_id' => $lesson->class_slot_id, 'starts_at' => $lesson->starts_at]);
})->throws(UniqueConstraintViolationException::class);
