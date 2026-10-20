<?php

use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\RaisedIssue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['name' => 'Academy Admin']);
    $this->tutor = User::factory()->tutor()->create(['name' => 'Tunde Bakare']);
    $this->group = ClassGroup::factory()->withSlot($this->tutor)->create(['name' => 'Year 5 Maths']);
    $this->lessonWithReport = function (string $start): Lesson {
        $startsAt = CarbonImmutable::parse($start, 'UTC');
        $lesson = Lesson::factory()->for($this->group)->create(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);
        LessonReport::factory()->for($lesson)->create(['author_role' => AttendeeRole::Tutor, 'submitted_at' => $startsAt->addHour()]);

        return $lesson;
    };
});

it('is only for admins', function (string $state) {
    $lesson = ($this->lessonWithReport)('2026-10-13 09:00:00');
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('admin.lesson-reports.index'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.lesson-reports.review', $lesson))->assertForbidden();
    $this->actingAs($user)->post(route('admin.lesson-reports.raise', $lesson), ['note' => 'Hi'])->assertForbidden();
})->with(['tutor', 'student', 'guardian']);

it('lists lessons needing review and selects the newest by default', function () {
    $older = ($this->lessonWithReport)('2026-10-12 09:00:00');
    $newer = ($this->lessonWithReport)('2026-10-13 09:00:00');

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/lesson-reports/index')
            ->has('lessons.data', 2)
            ->where('lessons.data.0.id', $newer->id)
            ->where('lessons.data.0.className', 'Year 5 Maths')
            ->where('counts.review', 2)
            ->where('filters.queue', 'review')
            ->where('filters.lesson', null)
            ->has('queues', 5)
            ->where('selected.id', $newer->id)
            ->where('selected.tutorReport.author', 'Tunde Bakare'));

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['lesson' => $older->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $older->id)->where('filters.lesson', $older->id));

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['queue' => 'reviewed']))
        ->assertInertia(fn (Assert $page) => $page->has('lessons.data', 0)->where('selected', null));
});

it('marks a lesson reviewed and moves to the next one with the same filters', function () {
    $lesson = ($this->lessonWithReport)('2026-10-13 09:00:00');
    $next = ($this->lessonWithReport)('2026-10-12 09:00:00');

    $this->actingAs($this->admin)
        ->from(route('admin.lesson-reports.index', ['queue' => 'review', 'class' => $this->group->id, 'lesson' => $lesson->id, 'evil' => 'x']))
        ->put(route('admin.lesson-reports.review', $lesson), ['next' => $next->id, 'seen' => '2026-10-13 10:00:00'])
        ->assertRedirect(route('admin.lesson-reports.index', ['queue' => 'review', 'class' => $this->group->id, 'lesson' => $next->id]))
        ->assertInertiaFlash('toast.message', 'Year 5 Maths marked as reviewed.');

    expect($lesson->fresh()->reports_reviewed_at->toDateTimeString())->toBe('2026-10-14 12:00:00')
        ->and($lesson->fresh()->reports_reviewed_by_id)->toBe($this->admin->id);

    $this->actingAs($this->admin)
        ->from(route('admin.lesson-reports.index'))
        ->put(route('admin.lesson-reports.review', $next), ['seen' => '2026-10-12 10:00:00'])
        ->assertRedirect(route('admin.lesson-reports.index'));
});

it('will not mark a lesson reviewed when a report arrived after the admin opened it', function () {
    $lesson = ($this->lessonWithReport)('2026-10-13 09:00:00');

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['lesson' => $lesson->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.latestSubmittedAt', '2026-10-13 10:00:00'));

    $student = User::factory()->student()->create();
    LessonReport::factory()->for($lesson)->create(['author_id' => $student->id, 'author_role' => AttendeeRole::Student, 'submitted_at' => now()]);

    $this->actingAs($this->admin)
        ->from(route('admin.lesson-reports.index', ['lesson' => $lesson->id]))
        ->put(route('admin.lesson-reports.review', $lesson), ['seen' => '2026-10-13 10:00:00'])
        ->assertRedirect(route('admin.lesson-reports.index', ['lesson' => $lesson->id]))
        ->assertInertiaFlash('toast.type', 'error');

    $this->actingAs($this->admin)
        ->post(route('admin.lesson-reports.raise', $lesson), ['note' => 'Please follow up.', 'seen' => '2026-10-13 10:00:00']);

    expect($lesson->fresh()->reports_reviewed_at)->toBeNull()
        ->and(RaisedIssue::count())->toBe(1);
});

it('raises an issue with the tutor and marks the lesson reviewed', function () {
    $lesson = ($this->lessonWithReport)('2026-10-13 09:00:00');

    $this->actingAs($this->admin)->post(route('admin.lesson-reports.raise', $lesson), ['note' => ''])
        ->assertSessionHasErrors(['note']);
    $this->actingAs($this->admin)->post(route('admin.lesson-reports.raise', $lesson), ['note' => str_repeat('a', 1001)])
        ->assertSessionHasErrors(['note']);

    $this->actingAs($this->admin)
        ->from(route('admin.lesson-reports.index', ['queue' => 'review']))
        ->post(route('admin.lesson-reports.raise', $lesson), ['note' => 'Please recap place value for Tolu.', 'seen' => '2026-10-13 10:00:00'])
        ->assertRedirect(route('admin.lesson-reports.index', ['queue' => 'review', 'lesson' => $lesson->id]))
        ->assertInertiaFlash('toast.message', 'Sent to Tunde Bakare.');

    $issue = RaisedIssue::sole();

    expect($issue->tutor_id)->toBe($this->tutor->id)
        ->and($issue->raised_by_id)->toBe($this->admin->id)
        ->and($issue->note)->toBe('Please recap place value for Tolu.')
        ->and($lesson->fresh()->reports_reviewed_at)->not->toBeNull();
});

it('shows admins how many classes need review in the sidebar', function () {
    ($this->lessonWithReport)('2026-10-13 09:00:00');
    ($this->lessonWithReport)('2026-10-12 09:00:00');

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('badges.classReports', 2));

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('badges', []));
});
