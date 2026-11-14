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
    $this->group = ClassGroup::factory()->withSlot($this->tutor)->create(['name' => 'Year 5 Maths']);
    $this->student = User::factory()->student()->create();
    $this->group->enrol($this->student);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

function studentRecord(Lesson $lesson, User $student)
{
    return $lesson->attendanceRecords()->where('user_id', $student->id)->first();
}

it('shows the open lesson with a live clock', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:50:00', 'UTC'));

    $this->actingAs($this->student)->get(route('check-in.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('check-in/index')
            ->where('card.lessonId', $this->lesson->id)
            ->where('card.title', 'Year 5 Maths')
            ->where('card.startsAt', '10:00')
            ->where('card.stamp', null)
            ->where('timezone', 'Africa/Lagos'));
});

it('says when the next class is when nothing is open', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 07:00:00', 'UTC'));

    $this->actingAs($this->student)->get(route('check-in.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('card', null)
            ->where('next', 'Sat 10:00 · Year 5 Maths'));
});

it('checks the student in with the right code and keeps the lesson scheduled', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:04:00', 'UTC'));

    $this->actingAs($this->student)
        ->post(route('check-in.store', $this->lesson), ['code' => $this->lesson->checkInCode()])
        ->assertRedirect(route('check-in.show'));

    $record = studentRecord($this->lesson, $this->student);
    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->minutes_late)->toBe(4)
        ->and($record->recorded_by_id)->toBe($this->student->id)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::Scheduled);
});

it('records nothing for a wrong code', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:04:00', 'UTC'));
    $wrong = $this->lesson->checkInCode() === '0000' ? '1111' : '0000';

    $this->actingAs($this->student)
        ->post(route('check-in.store', $this->lesson), ['code' => $wrong])
        ->assertSessionHasErrors(['code' => "That code isn't right. Check the number on your tutor's screen."]);

    expect(studentRecord($this->lesson, $this->student))->toBeNull();
});

it('locks out after five wrong codes', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:04:00', 'UTC'));
    $code = $this->lesson->checkInCode();
    $wrong = $code === '0000' ? '1111' : '0000';

    foreach (range(1, 5) as $try) {
        $this->actingAs($this->student)->post(route('check-in.store', $this->lesson), ['code' => $wrong]);
    }

    $this->actingAs($this->student)
        ->post(route('check-in.store', $this->lesson), ['code' => $code])
        ->assertSessionHasErrors(['code' => 'Too many tries. Ask your tutor.']);

    expect(studentRecord($this->lesson, $this->student))->toBeNull();
});

it('does not overwrite what the tutor already recorded', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:04:00', 'UTC'));
    app(RecordAttendance::class)->handle($this->lesson, $this->student, AttendanceStatus::Absent, $this->tutor);

    $this->actingAs($this->student)
        ->post(route('check-in.store', $this->lesson), ['code' => $this->lesson->checkInCode()]);

    expect(studentRecord($this->lesson, $this->student)->status)->toBe(AttendanceStatus::Absent);

    $this->actingAs($this->student)->get(route('check-in.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('card.stamp.status', 'absent')
            ->where('card.stamp.time', null)
            ->where('card.stamp.recordedBySelf', false));
});

it('refuses a lesson the student cannot check in to', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:04:00', 'UTC'));
    $other = Lesson::factory()->for(ClassGroup::factory()->withSlot($this->tutor))->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);

    $this->actingAs($this->student)
        ->post(route('check-in.store', $other), ['code' => $other->checkInCode()])
        ->assertForbidden();
});
