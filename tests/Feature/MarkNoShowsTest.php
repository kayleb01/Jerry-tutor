<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->group = ClassGroup::factory()->create();
    $this->ava = User::factory()->student()->create();
    $this->bola = User::factory()->student()->create();
    $this->group->enrol($this->ava);
    $this->group->enrol($this->bola);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

it('marks unrecorded people absent 15 minutes after the end and closes the lesson', function () {
    app(RecordAttendance::class)->handle($this->lesson, $this->ava, AttendanceStatus::Present, $this->lesson->tutor);
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:15:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    $records = $this->lesson->attendanceRecords()->get()->keyBy('user_id');
    expect($records)->toHaveCount(3)
        ->and($records[$this->ava->id]->status)->toBe(AttendanceStatus::Present)
        ->and($records[$this->bola->id]->status)->toBe(AttendanceStatus::Absent)
        ->and($records[$this->bola->id]->recorded_by_id)->toBeNull()
        ->and($records[$this->lesson->tutor_id]->status)->toBe(AttendanceStatus::Absent)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

it('waits until 15 minutes after the end', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:14:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($this->lesson->attendanceRecords()->count())->toBe(0)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::Scheduled);
});

it('skips cancelled lessons and students who have left', function () {
    $this->group->withdraw($this->bola);
    $cancelled = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 13:00:00', 'UTC'),
        'status' => LessonStatus::Cancelled,
    ]);
    $this->travelTo(CarbonImmutable::parse('2025-10-05 09:00:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($cancelled->attendanceRecords()->count())->toBe(0)
        ->and($this->lesson->attendanceRecords()->where('user_id', $this->bola->id)->exists())->toBeFalse();
});

it('still closes a lesson whose tutor has since been deactivated', function () {
    $this->lesson->tutor->delete();
    $this->travelTo(CarbonImmutable::parse('2025-10-04 11:00:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($this->lesson->fresh()->status)->toBe(LessonStatus::Completed)
        ->and($this->lesson->attendanceRecords()->count())->toBe(3);
});

it('is safe to run twice', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 11:00:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows');
    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($this->lesson->attendanceRecords()->count())->toBe(3);
});
