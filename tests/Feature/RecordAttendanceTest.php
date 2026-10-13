<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->student = User::factory()->student()->create();
    $this->group->enrol($this->student);
    $this->tutor = $this->lesson->tutor;
    $this->record = fn (User $who, AttendanceStatus $status, ?string $arrivedUtc = null) => app(RecordAttendance::class)
        ->handle($this->lesson, $who, $status, $this->tutor, $arrivedUtc ? CarbonImmutable::parse($arrivedUtc, 'UTC') : null);
});

it('treats arriving at the start, or within the same minute, as on time', function (string $arrival) {
    $record = ($this->record)($this->student, AttendanceStatus::Present, $arrival);

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->minutes_late)->toBeNull()
        ->and(LatenessReport::count())->toBe(0);
})->with(['2025-10-04 09:00:00', '2025-10-04 09:00:59']);

it('marks someone a minute or more late and writes a lateness report', function () {
    $record = ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:07:30');

    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->minutes_late)->toBe(7)
        ->and($record->attendee_role)->toBe(AttendeeRole::Student);

    $report = $record->latenessReport;
    expect($report->user_id)->toBe($this->student->id)
        ->and($report->attendee_role)->toBe(AttendeeRole::Student)
        ->and($report->class_name)->toBe('Year 5 Maths')
        ->and($report->lesson_date->toDateString())->toBe('2025-10-04')
        ->and($report->scheduled_start_at->toDateTimeString())->toBe('2025-10-04 09:00:00')
        ->and($report->arrived_at->toDateTimeString())->toBe('2025-10-04 09:07:30')
        ->and($report->minutes_late)->toBe(7);
});

it('applies exactly the same rules to the tutor', function () {
    $record = ($this->record)($this->tutor, AttendanceStatus::Present, '2025-10-04 09:01:00');

    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->attendee_role)->toBe(AttendeeRole::Tutor)
        ->and($record->latenessReport->attendee_role)->toBe(AttendeeRole::Tutor);
});

it('respects the grace period', function () {
    config(['academy.lateness_grace_minutes' => 5]);

    expect(($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:04:00')->status)->toBe(AttendanceStatus::Present)
        ->and(($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:06:00')->status)->toBe(AttendanceStatus::Late);
});

it('defaults a present arrival to the lesson start', function () {
    $record = ($this->record)($this->student, AttendanceStatus::Present);

    expect($record->arrived_at->toDateTimeString())->toBe('2025-10-04 09:00:00')
        ->and($record->status)->toBe(AttendanceStatus::Present);
});

it('keeps one record and one report, updating them on correction', function () {
    ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:10:00');
    $record = ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:03:00');

    expect($this->lesson->attendanceRecords()->count())->toBe(1)
        ->and(LatenessReport::count())->toBe(1)
        ->and($record->latenessReport->minutes_late)->toBe(3);
});

it('removes the report when someone is corrected to on time, absent or excused', function (AttendanceStatus $correction) {
    ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:10:00');

    $record = ($this->record)($this->student, $correction, $correction === AttendanceStatus::Present ? '2025-10-04 09:00:00' : null);

    expect($record->status)->toBe($correction)
        ->and(LatenessReport::count())->toBe(0);
    if ($correction !== AttendanceStatus::Present) {
        expect($record->arrived_at)->toBeNull()->and($record->minutes_late)->toBeNull();
    }
})->with([AttendanceStatus::Present, AttendanceStatus::Absent, AttendanceStatus::Excused]);

it('records who made the entry, or null for the system', function () {
    expect(($this->record)($this->student, AttendanceStatus::Absent)->recorded_by_id)->toBe($this->tutor->id);

    $system = app(RecordAttendance::class)->handle($this->lesson, $this->student, AttendanceStatus::Absent, null);
    expect($system->recorded_by_id)->toBeNull();
});

it('refuses people who are not part of the lesson', function () {
    ($this->record)(User::factory()->student()->create(), AttendanceStatus::Present);
})->throws(InvalidArgumentException::class);

it('still accepts a student who has since left the class, for corrections', function () {
    $this->group->withdraw($this->student);

    expect(($this->record)($this->student, AttendanceStatus::Absent)->status)->toBe(AttendanceStatus::Absent);
});

it('refuses Late as an input, because lateness is worked out', function () {
    ($this->record)($this->student, AttendanceStatus::Late, '2025-10-04 09:10:00');
})->throws(InvalidArgumentException::class);

it('can record only when nothing is recorded yet, leaving an existing record untouched', function () {
    ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:10:00');

    $result = app(RecordAttendance::class)->handle($this->lesson, $this->student, AttendanceStatus::Absent, null, onlyIfUnrecorded: true);

    expect($result->status)->toBe(AttendanceStatus::Late)
        ->and(LatenessReport::count())->toBe(1);
});
