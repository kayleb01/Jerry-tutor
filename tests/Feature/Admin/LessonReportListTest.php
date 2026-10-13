<?php

use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->admin = User::factory()->admin()->create();
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->other = ClassGroup::factory()->create(['name' => 'Year 7 Maths']);
    $this->lessonOn = fn (string $date, ClassGroup $group) => Lesson::factory()->for($group)->create([
        'starts_at' => CarbonImmutable::parse("{$date} 09:00:00", 'UTC'),
        'ends_at' => CarbonImmutable::parse("{$date} 10:00:00", 'UTC'),
    ]);
});

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('admin.lesson-reports.index'))->assertForbidden();
})->with(['tutor', 'student', 'guardian']);

it('lists reports from tutors and students, newest lesson first', function () {
    $older = ($this->lessonOn)('2025-10-01', $this->group);
    $newer = ($this->lessonOn)('2025-10-08', $this->group);
    LessonReport::factory()->for($older)->create(['went_well' => 'Older']);
    $student = User::factory()->student()->create(['name' => 'Ava']);
    LessonReport::factory()->for($newer)->create(['author_id' => $student->id, 'author_role' => AttendeeRole::Student, 'rating' => 5, 'went_well' => 'Newer']);

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/lesson-reports/index')
            ->has('reports.data', 2)
            ->where('reports.data.0.wentWell', 'Newer')
            ->where('reports.data.0.author', 'Ava')
            ->where('reports.data.0.authorRole', 'student')
            ->where('reports.data.0.rating', 5)
            ->where('reports.data.0.lessonDate', 'Wed 8 Oct 2025')
            ->where('reports.data.0.className', 'Year 5 Maths')
            ->has('classes', 2));
});

it('filters by class, author role and date range', function () {
    LessonReport::factory()->for(($this->lessonOn)('2025-10-01', $this->group))->create();
    LessonReport::factory()->for(($this->lessonOn)('2025-10-08', $this->other))->create();
    $student = User::factory()->student()->create();
    LessonReport::factory()->for(($this->lessonOn)('2025-10-15', $this->group))->create(['author_id' => $student->id, 'author_role' => AttendeeRole::Student]);

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['class' => $this->other->id]))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.className', 'Year 7 Maths'));

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['role' => 'student']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1));

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['from' => '2025-10-05', 'to' => '2025-10-10']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.className', 'Year 7 Maths'));
});

it('ignores filters it does not understand', function () {
    LessonReport::factory()->for(($this->lessonOn)('2025-10-01', $this->group))->create();

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['role' => 'admin', 'from' => 'yesterday', 'class' => 'abc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1));
});
