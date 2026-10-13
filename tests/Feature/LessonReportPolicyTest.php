<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->group = ClassGroup::factory()->create();
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->tutor = $this->lesson->tutor;
    $this->attended = fn (AttendanceStatus $status) => tap(User::factory()->student()->create(), function (User $student) use ($status) {
        $this->group->enrol($student);
        app(RecordAttendance::class)->handle($this->lesson, $student, $status, null);
    });
});

it('opens reporting from the start until 48 hours after the end', function (string $now, bool $allowed) {
    $student = ($this->attended)(AttendanceStatus::Present);
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    expect($this->tutor->can('submitReport', $this->lesson))->toBe($allowed)
        ->and($student->can('submitReport', $this->lesson))->toBe($allowed);
})->with([
    'before the start' => ['2025-10-04 08:59:00', false],
    'at the start' => ['2025-10-04 09:00:00', true],
    'just after the end' => ['2025-10-04 10:05:00', true],
    '48 hours after the end' => ['2025-10-06 10:00:00', true],
    'later than that' => ['2025-10-06 10:01:00', false],
]);

it('lets students who attended report', function (AttendanceStatus $status, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));

    expect(($this->attended)($status)->can('submitReport', $this->lesson))->toBe($allowed);
})->with([
    'present' => [AttendanceStatus::Present, true],
    'absent' => [AttendanceStatus::Absent, false],
    'excused' => [AttendanceStatus::Excused, false],
]);

it('refuses students with no record, other tutors, guardians, admins and cancelled lessons', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));
    $unrecorded = User::factory()->student()->create();
    $this->group->enrol($unrecorded);

    foreach ([$unrecorded, User::factory()->tutor()->create(), User::factory()->guardian()->create(), User::factory()->admin()->create()] as $user) {
        expect($user->can('submitReport', $this->lesson))->toBeFalse();
    }

    $this->lesson->update(['status' => LessonStatus::Cancelled]);
    expect($this->tutor->can('submitReport', $this->lesson))->toBeFalse();
});
