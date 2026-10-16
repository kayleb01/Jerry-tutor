<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->tutor = $this->lesson->tutor;
    $this->ava = User::factory()->student()->create();
    $this->group->enrol($this->ava);
    app(RecordAttendance::class)->handle($this->lesson, $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:06:00', 'UTC'));
});

function reportPayload(array $overrides = []): array
{
    return ['rating' => 4, 'went_well' => 'Fractions clicked.', 'issues' => '', 'recommendations' => 'More practice on mixed numbers.', ...$overrides];
}

it('shows the same report form to the tutor and to a student who attended late', function (string $who, string $role) {
    $user = $who === 'tutor' ? $this->tutor : $this->ava;

    $this->actingAs($user)->get(route('lessons.report.edit', $this->lesson))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lessons/report')
            ->where('lesson.className', 'Year 5 Maths')
            ->where('lesson.date', 'Saturday 4 October')
            ->where('authorRole', $role)
            ->where('report', null)
            ->where('closesAt', 'Mon 6 Oct, 11:00'));
})->with([['tutor', 'tutor'], ['student', 'student']]);

it('saves a report and updates it on a second submission', function () {
    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload())
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success');

    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload(['rating' => 5, 'issues' => 'Room was cold.']));

    $report = LessonReport::sole();
    expect($report->author_id)->toBe($this->ava->id)
        ->and($report->author_role)->toBe(AttendeeRole::Student)
        ->and($report->rating)->toBe(5)
        ->and($report->issues)->toBe('Room was cold.');

    $this->actingAs($this->ava)->get(route('lessons.report.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('report.rating', 5)->where('report.wentWell', 'Fractions clicked.'));
});

it('only ever shows the viewer their own report', function () {
    LessonReport::factory()->for($this->lesson)->create(['author_id' => $this->tutor->id, 'went_well' => 'Tutor view']);

    $this->actingAs($this->ava)->get(route('lessons.report.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('report', null));
});

it('needs at least something written and a rating from 1 to 5', function () {
    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), ['rating' => '', 'went_well' => '', 'issues' => '', 'recommendations' => ''])
        ->assertSessionHasErrors('went_well');

    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload(['rating' => 6]))
        ->assertSessionHasErrors('rating');

    expect(LessonReport::count())->toBe(0);
});

it('refuses people who may not report on this lesson', function (string $who) {
    $user = match ($who) {
        'absent student' => tap(User::factory()->student()->create(), function (User $student) {
            $this->group->enrol($student);
            app(RecordAttendance::class)->handle($this->lesson, $student, AttendanceStatus::Absent, null);
        }),
        'admin' => User::factory()->admin()->create(),
        'other tutor' => User::factory()->tutor()->create(),
    };

    $this->actingAs($user)->get(route('lessons.report.edit', $this->lesson))->assertForbidden();
    $this->actingAs($user)->put(route('lessons.report.update', $this->lesson), reportPayload())->assertForbidden();
})->with(['absent student', 'admin', 'other tutor']);

it('closes 48 hours after the lesson ends', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-06 10:01:00', 'UTC'));

    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload())->assertForbidden();
});

it('offers the report on the student dashboard and the tutor dashboard', function () {
    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('today.0.canReport', true)->where('today.0.reported', false));

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('week.0.id', $this->lesson->id)->where('week.0.report', 'needed'));

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('canReport', true));
});
