<?php

use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->tutor = User::factory()->tutor()->create();
    $this->group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 5 Maths', 'location' => 'Room 2']);
    $this->group->enrol(User::factory()->student()->create());

    $this->lessonAt = fn (string $startsAt, array $attributes = []): Lesson => Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse($startsAt, 'UTC'),
        'ends_at' => CarbonImmutable::parse($startsAt, 'UTC')->addHour(),
        ...$attributes,
    ]);
});

it('opens the register for the class running now, then the next one today', function () {
    $earlier = ($this->lessonAt)('2025-10-04 07:00:00');
    $running = ($this->lessonAt)('2025-10-04 09:00:00');
    $later = ($this->lessonAt)('2025-10-04 13:00:00');

    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));
    $this->actingAs($this->tutor)->get(route('registers.next'))
        ->assertRedirect(route('lessons.register.show', $running));

    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));
    $this->actingAs($this->tutor)->get(route('registers.next'))
        ->assertRedirect(route('lessons.register.show', $later));
});

it('opens the most recent past class on a day with no classes', function () {
    ($this->lessonAt)('2025-10-01 09:00:00');
    $recent = ($this->lessonAt)('2025-10-02 09:00:00');
    ($this->lessonAt)('2025-10-03 09:00:00', ['status' => LessonStatus::Cancelled]);
    ($this->lessonAt)('2025-10-09 09:00:00');

    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));
    $this->actingAs($this->tutor)->get(route('registers.next'))
        ->assertRedirect(route('lessons.register.show', $recent));
});

it('goes back to the dashboard with a message when the tutor has no classes', function () {
    $this->actingAs($this->tutor)->get(route('registers.next'))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.message', 'You have no classes to take a register for.');
});

it('only lets tutors use the register entry', function () {
    $this->actingAs(User::factory()->student()->create())->get(route('registers.next'))->assertForbidden();
});

it('shows an old register read-only and refuses changes to it', function () {
    $lesson = ($this->lessonAt)('2025-10-01 09:00:00');
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $lesson))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', false)
            ->where('editingNote', 'This register closed on Thu 2 Oct.')
            ->where('lesson.dateLong', 'Wednesday 1 October'));

    $this->actingAs($this->tutor)->put(route('lessons.register.update', $lesson), [
        'attendance' => [],
    ])->assertForbidden();
});

it('explains when a register is not open yet', function () {
    $lesson = ($this->lessonAt)('2025-10-04 13:00:00');
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', false)
            ->where('editingNote', 'The register opens at 13:30.'));
});

it('gives the tutor their check-in state and a plain editable register inside the window', function () {
    $lesson = ($this->lessonAt)('2025-10-04 09:00:00');
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)
            ->where('editingNote', null)
            ->where('checkIn.canCheckIn', true)
            ->where('checkIn.at', null)
            ->where('lesson.dateLong', 'Saturday 4 October'));

    $this->actingAs($this->tutor)->post(route('lessons.check-in', $lesson));

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkIn.at', '10:30')
            ->where('checkIn.minutesLate', 30)
            ->where('checkIn.canCheckIn', false));
});

it('never shows another tutor\'s register', function () {
    $lesson = ($this->lessonAt)('2025-10-04 09:00:00');
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));

    $this->actingAs(User::factory()->tutor()->create())
        ->get(route('lessons.register.show', $lesson))
        ->assertForbidden();
});

it('shows admins any register without a check-in badge', function () {
    $lesson = ($this->lessonAt)('2025-10-01 09:00:00');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('lessons.register.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEdit', true)
            ->where('editingNote', null)
            ->where('checkIn', null));
});
