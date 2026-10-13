<?php

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->lesson = Lesson::factory()->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->tutor = $this->lesson->tutor;
});

it('lets the lesson\'s tutor act from 30 minutes before until a day after', function (string $now, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    expect($this->tutor->can('checkIn', $this->lesson))->toBe($allowed)
        ->and($this->tutor->can('takeRegister', $this->lesson))->toBe($allowed);
})->with([
    'a week early' => ['2025-09-27 09:00:00', false],
    '31 minutes early' => ['2025-10-04 08:29:00', false],
    '30 minutes early' => ['2025-10-04 08:30:00', true],
    'during' => ['2025-10-04 09:30:00', true],
    'next morning' => ['2025-10-05 09:00:00', true],
    'two days later' => ['2025-10-06 10:01:00', false],
]);

it('never lets another tutor, a guardian or a student act', function (string $state) {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'));
    $someone = User::factory()->{$state}()->create();

    expect($someone->can('checkIn', $this->lesson))->toBeFalse()
        ->and($someone->can('takeRegister', $this->lesson))->toBeFalse()
        ->and($someone->can('recordTutorAttendance', $this->lesson))->toBeFalse();
})->with(['tutor', 'guardian', 'student']);

it('lets admins take any register and record the tutor, at any time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-01 09:00:00', 'UTC'));
    $admin = User::factory()->admin()->create();

    expect($admin->can('takeRegister', $this->lesson))->toBeTrue()
        ->and($admin->can('recordTutorAttendance', $this->lesson))->toBeTrue()
        ->and($admin->can('checkIn', $this->lesson))->toBeFalse()
        ->and($this->tutor->can('recordTutorAttendance', $this->lesson))->toBeFalse();
});

it('blocks everyone from a cancelled lesson', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'));
    $this->lesson->update(['status' => LessonStatus::Cancelled]);

    expect($this->tutor->can('takeRegister', $this->lesson))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('takeRegister', $this->lesson))->toBeFalse();
});
