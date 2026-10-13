<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Models\AttendanceRecord;
use App\Models\LatenessReport;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(fn () => config(['academy.timezone' => 'Africa/Lagos']));

it('labels attendance statuses for people', function () {
    expect(AttendanceStatus::Present->label())->toBe('On time')
        ->and(AttendanceStatus::Late->label())->toBe('Late')
        ->and(AttendeeRole::Tutor->value)->toBe('tutor');
});

it('converts lesson times to and from the academy timezone', function () {
    $lesson = Lesson::factory()->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);

    expect($lesson->localStart()->format('Y-m-d H:i'))->toBe('2025-10-04 10:00')
        ->and($lesson->localEnd()->format('H:i'))->toBe('11:00')
        ->and($lesson->localTimeToUtc('10:07')->toDateTimeString())->toBe('2025-10-04 09:07:00')
        ->and($lesson->isCancelled())->toBeFalse();

    $lesson->status = LessonStatus::Cancelled;
    expect($lesson->isCancelled())->toBeTrue();
});

it('still knows the tutor of a past lesson after they are deactivated', function () {
    $lesson = Lesson::factory()->create();
    $lesson->tutor->delete();

    expect($lesson->fresh()->tutor)->not->toBeNull();
});

it('links records and reports to their lesson and person', function () {
    $record = AttendanceRecord::factory()->create(['status' => AttendanceStatus::Late, 'minutes_late' => 5]);
    $report = LatenessReport::create([
        'attendance_record_id' => $record->id,
        'user_id' => $record->user_id,
        'attendee_role' => AttendeeRole::Student,
        'lesson_id' => $record->lesson_id,
        'class_name' => 'Year 5 Maths',
        'lesson_date' => '2025-10-04',
        'scheduled_start_at' => $record->lesson->starts_at,
        'arrived_at' => $record->lesson->starts_at->addMinutes(5),
        'minutes_late' => 5,
    ]);

    expect($record->latenessReport->is($report))->toBeTrue()
        ->and($record->lesson->attendanceRecords()->first()->is($record))->toBeTrue()
        ->and($record->lesson->latenessReports()->first()->is($report))->toBeTrue()
        ->and($report->user->is($record->user))->toBeTrue()
        ->and($report->lesson_date->toDateString())->toBe('2025-10-04');
});

it('keeps one record per person per lesson', function () {
    $record = AttendanceRecord::factory()->create();

    AttendanceRecord::factory()->create(['lesson_id' => $record->lesson_id, 'user_id' => $record->user_id]);
})->throws(UniqueConstraintViolationException::class);
