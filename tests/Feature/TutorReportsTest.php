<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use App\Services\TutorReports;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-20 12:00:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create();
    $this->group = ClassGroup::factory()->create(['tutor_id' => $this->tutor->id]);
    $this->reports = app(TutorReports::class);

    $this->lessonAt = function (string $startsAt, ?User $tutor = null, array $overrides = []): Lesson {
        $start = CarbonImmutable::parse($startsAt, 'UTC');

        return Lesson::factory()->for($this->group)->create([
            'tutor_id' => ($tutor ?? $this->tutor)->id,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            ...$overrides,
        ]);
    };
    $this->record = fn (Lesson $lesson, User $user, AttendanceStatus $status, AttendeeRole $role = AttendeeRole::Student, ?int $recordedBy = 1) => AttendanceRecord::factory()->create([
        'lesson_id' => $lesson->id,
        'user_id' => $user->id,
        'attendee_role' => $role,
        'status' => $status,
        'recorded_by_id' => $recordedBy,
    ]);
    $this->attendedLesson = function (string $startsAt, ?User $tutor = null, array $overrides = []): Lesson {
        $lesson = ($this->lessonAt)($startsAt, $tutor, $overrides);
        ($this->record)($lesson, User::factory()->student()->create(), AttendanceStatus::Present);

        return $lesson;
    };
    $this->needing = fn (?User $tutor = null): array => $this->reports->needingReport($tutor ?? $this->tutor)->pluck('id')->all();
});

it('needs a report for a started class the tutor taught and someone attended, however old', function () {
    $old = ($this->attendedLesson)('2025-08-21 09:00:00');

    expect(($this->needing)())->toBe([$old->id]);
});

it('does not need a report for an unstarted, cancelled, missed or empty class', function () {
    ($this->attendedLesson)('2025-10-21 09:00:00');
    ($this->attendedLesson)('2025-10-19 09:00:00', null, ['status' => LessonStatus::Cancelled]);

    $missed = ($this->lessonAt)('2025-10-18 09:00:00');
    ($this->record)($missed, User::factory()->student()->create(), AttendanceStatus::Present);
    ($this->record)($missed, $this->tutor, AttendanceStatus::Absent, AttendeeRole::Tutor);

    $noShow = ($this->lessonAt)('2025-10-17 09:00:00');
    ($this->record)($noShow, User::factory()->student()->create(), AttendanceStatus::Absent, AttendeeRole::Student, null);
    ($this->record)($noShow, $this->tutor, AttendanceStatus::Absent, AttendeeRole::Tutor, null);

    expect(($this->needing)())->toBe([]);
});

it('treats a draft as still needing a report and a submitted report as done', function () {
    $drafted = ($this->attendedLesson)('2025-10-19 09:00:00');
    $done = ($this->attendedLesson)('2025-10-18 09:00:00');
    LessonReport::factory()->draft()->for($drafted)->create();
    LessonReport::factory()->for($done)->create();

    expect(($this->needing)())->toBe([$drafted->id]);
});

it('never lists another tutor\'s classes', function () {
    $other = User::factory()->tutor()->create();
    $theirs = ($this->attendedLesson)('2025-10-19 09:00:00', $other);

    expect(($this->needing)())->toBe([])
        ->and(($this->needing)($other))->toBe([$theirs->id]);
});

it('defaults to the class that just ended, else the newest outstanding one', function () {
    $older = ($this->attendedLesson)('2025-10-18 09:00:00');
    $justEnded = ($this->attendedLesson)('2025-10-20 10:30:00');

    expect($this->reports->defaultLesson($this->tutor)->id)->toBe($justEnded->id);

    LessonReport::factory()->for($justEnded)->create();
    expect($this->reports->defaultLesson($this->tutor)->id)->toBe($older->id);

    LessonReport::factory()->for($older)->create();
    expect($this->reports->defaultLesson($this->tutor))->toBeNull();
});

it('lets the tutor report on an old class but not another tutor\'s', function () {
    $old = ($this->attendedLesson)('2025-08-21 09:00:00');
    $submitted = ($this->attendedLesson)('2025-08-22 09:00:00');
    LessonReport::factory()->for($submitted)->create();
    $other = User::factory()->tutor()->create();

    expect($this->tutor->can('submitReport', $old))->toBeTrue()
        ->and($this->tutor->can('submitReport', $submitted))->toBeTrue()
        ->and($other->can('submitReport', $old))->toBeFalse();

    $submitted->update(['status' => LessonStatus::Cancelled]);
    expect($this->tutor->can('submitReport', $submitted))->toBeFalse();
});
