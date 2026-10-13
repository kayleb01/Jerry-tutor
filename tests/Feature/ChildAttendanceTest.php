<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2025-10-15 12:00:00', 'UTC'));
    $this->parent = User::factory()->guardian()->create();
    $this->ava = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava']);
    $this->parent->linkChild($this->ava);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->group->enrol($this->ava);
    $record = app(RecordAttendance::class);
    $lesson = fn (string $start) => Lesson::factory()->for($this->group)->create(['starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour()]);
    $record->handle($lesson('2025-10-04 09:00:00'), $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:08:00', 'UTC'));
    $record->handle($lesson('2025-10-11 09:00:00'), $this->ava, AttendanceStatus::Absent, null);
});

it('shows a parent their child\'s attendance and lateness, newest first', function () {
    $this->actingAs($this->parent)->get(route('children.attendance', $this->ava))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/attendance')
            ->where('child.name', 'Ava')
            ->where('child.yearGroup', 'Year 5')
            ->where('stats.attendance', 50)
            ->where('stats.onTime', 0)
            ->where('stats.late', 1)
            ->where('stats.absent', 1)
            ->has('records', 2)
            ->where('records.0.date', 'Sat 11 Oct')
            ->where('records.0.status', 'absent')
            ->where('records.1.status', 'late')
            ->where('records.1.minutesLate', 8)
            ->where('records.1.time', '10:00–11:00')
            ->where('records.1.className', 'Year 5 Maths'));
});

it('adds attendance to the children list', function () {
    $this->actingAs($this->parent)->get(route('children.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.0.attendance', 50)->where('children.0.late', 1));
});

it('hides other families\' children', function () {
    $this->actingAs(User::factory()->guardian()->create())
        ->get(route('children.attendance', $this->ava))
        ->assertNotFound();
});

it('is only for guardians', function () {
    $this->actingAs(User::factory()->tutor()->create())
        ->get(route('children.attendance', $this->ava))
        ->assertNotFound();
});
