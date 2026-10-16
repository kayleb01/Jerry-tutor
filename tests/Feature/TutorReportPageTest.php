<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use App\Services\ClassReportInbox;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-20 12:00:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create(['name' => 'Ms Okafor']);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 · Geometry', 'tutor_id' => $this->tutor->id]);

    $this->attendedLesson = function (string $startsAt, int $present = 1, int $absent = 0): Lesson {
        $start = CarbonImmutable::parse($startsAt, 'UTC');
        $lesson = Lesson::factory()->for($this->group)->create([
            'tutor_id' => $this->tutor->id,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
        ]);
        AttendanceRecord::factory()->count($present)->create(['lesson_id' => $lesson->id, 'status' => AttendanceStatus::Present, 'recorded_by_id' => $this->tutor->id]);
        AttendanceRecord::factory()->count($absent)->create(['lesson_id' => $lesson->id, 'status' => AttendanceStatus::Absent, 'recorded_by_id' => $this->tutor->id]);

        return $lesson;
    };
    $this->lesson = ($this->attendedLesson)('2025-10-20 09:00:00');
    $this->url = route('lessons.report.update', $this->lesson);
    $this->payload = fn (array $overrides = []): array => ['action' => 'submit', 'rating' => 4, 'went_well' => 'Good pace.', 'issues' => '', 'recommendations' => '', ...$overrides];
});

it('saves a private draft and submits it later', function () {
    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['action' => 'draft']))->assertRedirect();

    expect(LessonReport::sole()->submitted_at)->toBeNull();
    expect(app(ClassReportInbox::class)->counts(app(ClassReportInbox::class)->filters([]))['review'])->toBe(0);

    $this->actingAs($this->tutor)->put($this->url, ($this->payload)())->assertRedirect(route('dashboard'));

    expect(LessonReport::sole()->submitted_at)->not->toBeNull();
    expect(app(ClassReportInbox::class)->counts(app(ClassReportInbox::class)->filters([]))['review'])->toBe(1);
});

it('refuses a draft once the report is submitted', function () {
    $this->actingAs($this->tutor)->put($this->url, ($this->payload)())->assertRedirect();

    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['action' => 'draft']))->assertUnprocessable();
    expect(LessonReport::sole()->submitted_at)->not->toBeNull();
});

it('needs a rating to submit but not to save a draft', function () {
    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['rating' => '']))->assertSessionHasErrors('rating');
    expect(LessonReport::count())->toBe(0);

    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['action' => 'draft', 'rating' => '', 'went_well' => '']))->assertSessionHasNoErrors();
    expect(LessonReport::sole()->rating)->toBeNull();

    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['action' => 'draft', 'went_well' => str_repeat('a', 2001)]))->assertSessionHasErrors('went_well');
});

it('stores the recommendation chips, the free text and the issue flag', function () {
    $this->actingAs($this->tutor)->put($this->url, ($this->payload)([
        'recommended_actions' => ['follow_up_quiz', 'speak_to_parent'],
        'recommendations' => 'Try the mixed paper.',
        'raises_issue' => true,
    ]))->assertSessionHasNoErrors();

    $report = LessonReport::sole();
    expect($report->recommended_actions->map->value->all())->toBe(['follow_up_quiz', 'speak_to_parent'])
        ->and($report->recommendations)->toBe('Try the mixed paper.')
        ->and($report->raises_issue)->toBeTrue();

    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['recommended_actions' => ['nonsense']]))
        ->assertSessionHasErrors('recommended_actions.0');
});

it('ignores the issue flag from students', function () {
    $student = User::factory()->student()->create();
    $this->group->enrol($student);
    app(RecordAttendance::class)->handle($this->lesson, $student, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-20 09:05:00', 'UTC'));

    $this->actingAs($student)->put($this->url, ['rating' => 3, 'went_well' => 'Fun', 'raises_issue' => true])->assertSessionHasNoErrors();

    expect(LessonReport::sole()->raises_issue)->toBeFalse();
});

it('flags a report that raises an issue for the admin team', function () {
    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['raises_issue' => true]));
    $inbox = app(ClassReportInbox::class);

    expect($inbox->detail($this->lesson->fresh())['tutorReport'])->toMatchArray(['needsAttention' => true, 'raisesIssue' => true]);
});

it('offers only classes that still need a report, plus the one being viewed', function () {
    $newest = ($this->attendedLesson)('2025-10-19 16:30:00');
    $older = ($this->attendedLesson)('2025-10-12 16:30:00');
    $submitted = ($this->attendedLesson)('2025-10-13 16:30:00');
    LessonReport::factory()->for($submitted)->create(['author_id' => $this->tutor->id]);
    ($this->attendedLesson)('2025-10-14 16:30:00', 0, 2);

    $this->actingAs($this->tutor)->get(route('lessons.report.edit', $submitted))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classOptions', [
                ['id' => $this->lesson->id, 'label' => 'Year 5 · Geometry · Mon 20 Oct, 10:00'],
                ['id' => $newest->id, 'label' => 'Year 5 · Geometry · Sun 19 Oct, 17:30'],
                ['id' => $submitted->id, 'label' => 'Year 5 · Geometry · Mon 13 Oct, 17:30'],
                ['id' => $older->id, 'label' => 'Year 5 · Geometry · Sun 12 Oct, 17:30'],
            ])
            ->has('recommendationOptions', 5));
});

it('shows the class panel with attendance', function () {
    $lesson = ($this->attendedLesson)('2025-10-19 16:30:00', 9, 1);

    $this->actingAs($this->tutor)->get(route('lessons.report.edit', $lesson))
        ->assertInertia(fn (Assert $page) => $page->where('panel', [
            'date' => 'Sun 19 Oct 2025',
            'time' => '17:30 – 18:30',
            'tutor' => 'Ms Okafor',
            'attendance' => '9 of 10 present',
        ]));

    $noShows = ($this->attendedLesson)('2025-10-18 16:30:00', 1, 0);
    AttendanceRecord::query()->where('lesson_id', $noShows->id)->update(['recorded_by_id' => null, 'status' => AttendanceStatus::Absent]);
    AttendanceRecord::factory()->create(['lesson_id' => $noShows->id, 'status' => AttendanceStatus::Present, 'attendee_role' => AttendeeRole::Tutor, 'user_id' => $this->tutor->id, 'recorded_by_id' => $this->tutor->id]);

    $this->actingAs($this->tutor)->get(route('lessons.report.edit', $noShows))
        ->assertInertia(fn (Assert $page) => $page->where('panel.attendance', 'Register not taken'));
});

it('sends the sidebar link to the default class, or says all caught up', function () {
    $this->actingAs($this->tutor)->get(route('reports.next'))->assertRedirect(route('lessons.report.edit', $this->lesson));

    LessonReport::factory()->for($this->lesson)->create(['author_id' => $this->tutor->id]);

    $this->actingAs($this->tutor)->get(route('reports.next'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('lessons/report-caught-up'));

    $this->actingAs(User::factory()->student()->create())->get(route('reports.next'))->assertForbidden();
});

it('still opens and corrects a report submitted before drafts existed', function () {
    LessonReport::factory()->for($this->lesson)->create([
        'author_id' => $this->tutor->id, 'rating' => 3, 'went_well' => 'Old text', 'recommendations' => 'Old rec',
    ]);

    $this->actingAs($this->tutor)->get(route('lessons.report.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.wentWell', 'Old text')
            ->where('report.isDraft', false)
            ->where('report.raisesIssue', false)
            ->where('report.recommendedActions', []));

    $this->actingAs($this->tutor)->put($this->url, ($this->payload)(['rating' => 5]))->assertRedirect(route('dashboard'));
    expect(LessonReport::sole()->rating)->toBe(5);
});

it('refuses another tutor\'s report page', function () {
    $other = User::factory()->tutor()->create();

    $this->actingAs($other)->get(route('lessons.report.edit', $this->lesson))->assertForbidden();
    $this->actingAs($other)->put($this->url, ($this->payload)())->assertForbidden();
});

it('offers students the recommendation chips but not the picker or panel', function () {
    $student = User::factory()->student()->create();
    $this->group->enrol($student);
    app(RecordAttendance::class)->handle($this->lesson, $student, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-20 09:05:00', 'UTC'));

    $this->actingAs($student)->get(route('lessons.report.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->has('recommendationOptions', 5)->where('classOptions', null)->where('panel', null));
});
