<?php

use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->tutor = User::factory()->tutor()->create();
    $this->group = ClassGroup::factory()->withSlot($this->tutor)->create();
    $this->student = User::factory()->student()->create();
    $this->group->enrol($this->student);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

it('generates a four digit code once and keeps it', function () {
    $code = $this->lesson->checkInCode();

    expect($code)->toMatch('/^\d{4}$/')
        ->and($this->lesson->checkInCode())->toBe($code)
        ->and($this->lesson->fresh()->check_in_code)->toBe($code);
});

it('lets an enrolled student check in from 30 minutes before start until the end', function (string $at, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse($at, 'UTC'));

    expect($this->student->can('studentCheckIn', $this->lesson))->toBe($allowed);
})->with([
    'just before the window' => ['2025-10-04 08:29:59', false],
    'window opens' => ['2025-10-04 08:30:00', true],
    'lesson ends' => ['2025-10-04 10:00:00', true],
    'just after the end' => ['2025-10-04 10:00:01', false],
]);

it('refuses a student who left the class', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:05:00', 'UTC'));
    $this->group->withdraw($this->student);

    expect($this->student->can('studentCheckIn', $this->lesson))->toBeFalse();
});

it('refuses a cancelled lesson, a tutor and a student of another class', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:05:00', 'UTC'));
    $outsider = User::factory()->student()->create();

    expect($this->tutor->can('studentCheckIn', $this->lesson))->toBeFalse()
        ->and($outsider->can('studentCheckIn', $this->lesson))->toBeFalse();

    $this->lesson->update(['status' => LessonStatus::Cancelled]);

    expect($this->student->can('studentCheckIn', $this->lesson->fresh()))->toBeFalse();
});

it('returns the stored code even when another request generated it first', function () {
    $stale = Lesson::find($this->lesson->id);
    $stored = $this->lesson->checkInCode();

    expect($stale->checkInCode())->toBe($stored)
        ->and($this->lesson->fresh()->check_in_code)->toBe($stored);
});
