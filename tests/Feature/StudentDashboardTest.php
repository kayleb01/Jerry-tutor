<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    // Wednesday 15 October 2025, 12:00 in Lagos.
    $this->travelTo(CarbonImmutable::parse('2025-10-15 11:00:00', 'UTC'));
    $this->ava = User::factory()->student(YearGroup::Year5)->create();
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths', 'location' => 'Room 1']);
    $this->group->enrol($this->ava);
    $this->lessonAt = fn (string $utcStart, int $minutes = 60, ?ClassGroup $group = null) => Lesson::factory()->for($group ?? $this->group)->create([
        'starts_at' => CarbonImmutable::parse($utcStart, 'UTC'),
        'ends_at' => CarbonImmutable::parse($utcStart, 'UTC')->addMinutes($minutes),
    ]);
});

it('shows a friendly empty dashboard for a student with nothing yet', function () {
    $this->actingAs(User::factory()->student(YearGroup::Year3)->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/student')
            ->where('header.yearGroup', 'Year 3')
            ->where('header.weekLabel', 'Week of 13 – 19 October')
            ->where('stats.attendance', null)
            ->where('stats.onTime', null)
            ->where('stats.classTime', '0m')
            ->where('stats.lessonsThisWeek', 0)
            ->has('strands', 4)
            ->where('strands.0.name', 'Number')
            ->where('strands.3.name', 'Geometry')
            ->has('today', 0)
            ->has('week', 0));
});

it('summarises today in academy time with the student\'s own attendance', function () {
    $finished = ($this->lessonAt)('2025-10-15 08:00:00', 45);  // 09:00–09:45 Lagos, already over
    ($this->lessonAt)('2025-10-15 10:30:00', 90);              // 11:30–13:00, in progress
    ($this->lessonAt)('2025-10-15 15:00:00', 60);              // 16:00–17:00, upcoming
    app(RecordAttendance::class)->handle($finished, $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-15 08:05:00', 'UTC'));

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('today', 3)
            ->where('today.0.className', 'Year 5 Maths')
            ->where('today.0.time', '09:00–09:45')
            ->where('today.0.location', 'Room 1')
            ->where('today.0.minutes', 45)
            ->where('today.0.state', 'finished')
            ->where('today.0.attendance', 'late')
            ->where('today.1.state', 'in_progress')
            ->where('today.2.state', 'upcoming')
            ->where('today.2.attendance', null)
            ->where('todayMinutes', 195));
});

it('works out attendance and punctuality over the last 30 days', function () {
    $record = app(RecordAttendance::class);
    $record->handle(($this->lessonAt)('2025-10-01 09:00:00'), $this->ava, AttendanceStatus::Present, null);
    $record->handle(($this->lessonAt)('2025-10-04 09:00:00'), $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:10:00', 'UTC'));
    $record->handle(($this->lessonAt)('2025-10-08 09:00:00'), $this->ava, AttendanceStatus::Absent, null);
    $record->handle(($this->lessonAt)('2025-10-11 09:00:00'), $this->ava, AttendanceStatus::Excused, null);
    $record->handle(($this->lessonAt)('2025-08-01 09:00:00'), $this->ava, AttendanceStatus::Absent, null); // older than 30 days

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.attendance', 67) // 2 attended of 3 (excused not counted)
            ->where('stats.onTime', 50));   // 1 on time of 2 attended
});

it('lists the week Monday to Sunday and totals class time, skipping cancelled lessons', function () {
    ($this->lessonAt)('2025-10-13 09:00:00', 60);                          // Monday
    ($this->lessonAt)('2025-10-15 15:00:00', 39);                          // Wednesday
    tap(($this->lessonAt)('2025-10-17 09:00:00', 60), fn (Lesson $lesson) => $lesson->update(['status' => LessonStatus::Cancelled])); // Friday
    ($this->lessonAt)('2025-10-19 22:30:00', 60);                          // Sunday 19 Oct 23:30 Lagos
    ($this->lessonAt)('2025-10-19 23:30:00', 60);                          // Monday 20 Oct 00:30 Lagos → next week

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.lessonsThisWeek', 3)
            ->where('stats.classTime', '2h 39m')
            ->has('week', 4)
            ->where('week.0.name', 'Monday')
            ->where('week.0.date', '13 Oct')
            ->where('week.0.minutes', 60)
            ->where('week.2.name', 'Friday')
            ->where('week.2.lessons.0.state', 'cancelled')
            ->where('week.2.minutes', 0)
            ->where('week.3.name', 'Sunday'));

    $this->actingAs($this->ava)->get(route('dashboard', ['week' => 'next']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextWeek', true)
            ->where('header.weekLabel', 'Week of 20 – 26 October')
            ->has('week', 1)
            ->where('week.0.name', 'Monday')
            ->where('week.0.lessons.0.time', '00:30–01:30'));
});

it('leaves out classes the student has left and never shows classmates\' records', function () {
    $left = ClassGroup::factory()->create(['name' => 'Old Class']);
    $left->enrol($this->ava);
    $left->withdraw($this->ava);
    ($this->lessonAt)('2025-10-15 15:00:00', 60, $left);

    $lesson = ($this->lessonAt)('2025-10-15 08:00:00', 45);
    $classmate = User::factory()->student()->create();
    $this->group->enrol($classmate);
    app(RecordAttendance::class)->handle($lesson, $classmate, AttendanceStatus::Absent, null);

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('today', 1)
            ->where('today.0.className', 'Year 5 Maths')
            ->where('today.0.attendance', null)
            ->where('stats.attendance', null));
});

it('shows whole hours without a trailing 0m', function () {
    ($this->lessonAt)('2025-10-16 09:00:00', 60);

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('stats.classTime', '1h'));
});
