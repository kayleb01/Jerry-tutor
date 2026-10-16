<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->tutor = User::factory()->tutor()->create();
    $this->group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 5 Maths', 'location' => 'Room 1']);
    $this->group->enrol(User::factory()->student()->create());
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

it('shows the tutor their next class in academy time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:45:00', 'UTC'));
    Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-11 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-11 10:00:00', 'UTC'),
    ]);

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/tutor')
            ->where('nextLesson.id', $this->lesson->id)
            ->where('nextLesson.className', 'Year 5 Maths')
            ->where('nextLesson.time', '10:00 – 11:00')
            ->where('nextLesson.location', 'Room 1')
            ->where('nextLesson.students', 1)
            ->where('nextLesson.checkedInAt', null)
            ->where('nextLesson.canCheckIn', true)
            ->has('week', 1));
});

it('checks the tutor in on time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:58:00', 'UTC'));

    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson))
        ->assertRedirect(route('lessons.register.show', $this->lesson));

    $record = $this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first();
    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::InProgress);
});

it('records a late check-in and its lateness report', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:12:00', 'UTC'));

    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson));

    $record = $this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first();
    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->minutes_late)->toBe(12)
        ->and($record->latenessReport)->not->toBeNull();

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextLesson.checkedInAt', '10:12')
            ->where('nextLesson.minutesLate', 12));
});

it('keeps the first check-in time if the tutor presses it again', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:58:00', 'UTC'));
    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson));

    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:20:00', 'UTC'));
    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson));

    expect($this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first()->status)
        ->toBe(AttendanceStatus::Present);
});

it('refuses check-in for someone else\'s lesson or too early', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:58:00', 'UTC'));
    $this->actingAs(User::factory()->tutor()->create())->post(route('lessons.check-in', $this->lesson))->assertForbidden();

    $this->travelTo(CarbonImmutable::parse('2025-10-03 09:00:00', 'UTC'));
    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson))->assertForbidden();

    expect($this->lesson->attendanceRecords()->count())->toBe(0);
});

it('will not let check-in overwrite a record an admin or the no-show job already made', function (AttendanceStatus $existing) {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:05:00', 'UTC'));
    app(RecordAttendance::class)->handle($this->lesson, $this->tutor, $existing, null);

    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson))
        ->assertInertiaFlash('toast.type', 'error');

    $record = $this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first();
    expect($record->status)->toBe($existing)
        ->and($record->latenessReport)->toBeNull();

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('nextLesson.canCheckIn', false));
})->with([AttendanceStatus::Absent, AttendanceStatus::Excused]);
