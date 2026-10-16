<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use App\Services\ReportsDue;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->tutor = $this->group->tutor;
    // Monday 13 Oct 2025, 17:00–18:00 Lagos.
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-13 16:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-13 17:00:00', 'UTC'),
    ]);
    $this->ava = User::factory()->student()->create();
    $this->group->enrol($this->ava);
    app(RecordAttendance::class)->handle($this->lesson, $this->ava, AttendanceStatus::Present, null);
    // Tuesday morning: the lesson is no longer "today".
    $this->travelTo(CarbonImmutable::parse('2025-10-14 08:00:00', 'UTC'));
});

it('lists yesterday\'s lesson for the tutor and the student to report on', function (string $who) {
    $user = $who === 'tutor' ? $this->tutor : $this->ava;

    expect(app(ReportsDue::class)->for($user))->toBe([[
        'id' => $this->lesson->id,
        'className' => 'Year 5 Maths',
        'when' => 'Mon 13 Oct, 17:00',
        'closesAt' => 'Wed 15 Oct, 18:00',
    ]]);

    if ($who === 'student') {
        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('reportsDue', 1)->where('reportsDue.0.id', $this->lesson->id));
    }
})->with(['tutor', 'student']);

it('drops a lesson once reported or once the window closes', function () {
    LessonReport::factory()->for($this->lesson)->create(['author_id' => $this->tutor->id]);

    expect(app(ReportsDue::class)->for($this->tutor))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2025-10-15 17:01:00', 'UTC'));
    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('reportsDue', 0));
});

it('does not ask absent students or other tutors', function () {
    $absent = User::factory()->student()->create();
    $this->group->enrol($absent);
    $this->travelTo(CarbonImmutable::parse('2025-10-13 17:30:00', 'UTC'));
    app(RecordAttendance::class)->handle($this->lesson, $absent, AttendanceStatus::Absent, null);
    $this->travelTo(CarbonImmutable::parse('2025-10-14 08:00:00', 'UTC'));

    $this->actingAs($absent)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('reportsDue', 0));
    expect(app(ReportsDue::class)->for(User::factory()->tutor()->create()))->toBe([]);
});

it('no longer sends reportsDue to the tutor dashboard', function () {
    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->missing('reportsDue'));
});
