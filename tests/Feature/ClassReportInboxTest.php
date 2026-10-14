<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\RaisedIssue;
use App\Models\User;
use App\Services\ClassReportInbox;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['name' => 'Academy Admin']);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->inbox = app(ClassReportInbox::class);
});

function inboxLesson(string $start, array $attributes = [], ?ClassGroup $group = null): Lesson
{
    $startsAt = CarbonImmutable::parse($start, 'UTC');

    return Lesson::factory()->for($group ?? test()->group)->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        ...$attributes,
    ]);
}

function inboxAttend(Lesson $lesson, AttendanceStatus $status = AttendanceStatus::Present): AttendanceRecord
{
    return AttendanceRecord::factory()->for($lesson)->create(['status' => $status]);
}

function inboxReport(Lesson $lesson, AttendeeRole $role, array $attributes = []): LessonReport
{
    return LessonReport::factory()->for($lesson)->create([
        'author_id' => $role === AttendeeRole::Tutor ? $lesson->tutor_id : User::factory()->student()->create()->id,
        'author_role' => $role,
        'submitted_at' => $lesson->ends_at,
        ...$attributes,
    ]);
}

function inboxReviewed(Lesson $lesson, string $at): Lesson
{
    $lesson->forceFill(['reports_reviewed_at' => CarbonImmutable::parse($at, 'UTC'), 'reports_reviewed_by_id' => test()->admin->id])->save();

    return $lesson;
}

function inboxIds(string $queue, array $filters = []): array
{
    $inbox = app(ClassReportInbox::class);

    return $inbox->query($inbox->filters([...$filters, 'queue' => $queue]))->orderBy('id')->pluck('id')->all();
}

function inboxRow(Lesson $lesson): array
{
    return app(ClassReportInbox::class)->row($lesson->fresh()->load(['classGroup', 'tutor', 'lessonReports', 'raisedIssues']), CarbonImmutable::now());
}

it('records reviews and raised issues on a lesson', function () {
    $lesson = inboxLesson('2026-10-13 09:00:00');
    inboxReviewed($lesson, '2026-10-13 12:00:00');
    $issue = RaisedIssue::factory()->for($lesson)->create();

    $lesson = $lesson->fresh();

    expect($lesson->reports_reviewed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($lesson->reportsReviewedBy->is($this->admin))->toBeTrue()
        ->and($lesson->raisedIssues)->toHaveCount(1)
        ->and($issue->tutor_id)->toBe($lesson->tutor_id)
        ->and($issue->acknowledged_at)->toBeNull()
        ->and(RaisedIssue::factory()->acknowledged()->create()->acknowledged_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('sorts lessons into queues and never lists cancelled or future ones', function () {
    $unreviewed = inboxLesson('2026-10-13 09:00:00');
    inboxReport($unreviewed, AttendeeRole::Student);

    $done = inboxLesson('2026-10-12 09:00:00');
    inboxAttend($done);
    inboxReport($done, AttendeeRole::Tutor);
    inboxReviewed($done, '2026-10-13 08:00:00');

    $noTutorReport = inboxLesson('2026-10-11 09:00:00');
    inboxAttend($noTutorReport);

    $raised = inboxLesson('2026-10-10 09:00:00');
    inboxAttend($raised);
    inboxReport($raised, AttendeeRole::Tutor);
    inboxReviewed($raised, '2026-10-11 08:00:00');
    RaisedIssue::factory()->for($raised)->create();

    $cancelled = inboxLesson('2026-10-13 15:00:00', ['status' => LessonStatus::Cancelled]);
    inboxAttend($cancelled);
    inboxReport($cancelled, AttendeeRole::Tutor);

    inboxAttend(inboxLesson('2026-10-15 09:00:00'));

    inboxLesson('2026-10-09 09:00:00');

    expect(inboxIds('review'))->toBe([$unreviewed->id])
        ->and(inboxIds('raised'))->toBe([$raised->id])
        ->and(inboxIds('missing'))->toBe([$noTutorReport->id])
        ->and(inboxIds('reviewed'))->toBe([$done->id, $raised->id])
        ->and(inboxIds('all'))->toBe([$unreviewed->id, $done->id, $noTutorReport->id, $raised->id]);
});

it('puts a lesson back in review when a report arrives after the review', function () {
    $lesson = inboxLesson('2026-10-12 09:00:00');
    inboxReport($lesson, AttendeeRole::Tutor);
    inboxReviewed($lesson, '2026-10-13 10:00:00');

    expect(inboxIds('review'))->toBe([]);

    inboxReport($lesson, AttendeeRole::Student, ['submitted_at' => CarbonImmutable::parse('2026-10-13 11:00:00', 'UTC')]);

    expect(inboxIds('review'))->toBe([$lesson->id])
        ->and(inboxIds('reviewed'))->toBe([]);
});

it('filters by class, tutor, dates and search, and counts every queue', function () {
    $tunde = User::factory()->tutor()->create(['name' => 'Tunde Bakare']);
    $ada = User::factory()->tutor()->create(['name' => 'Ada Obi']);
    $year5 = ClassGroup::factory()->for($tunde, 'tutor')->create(['name' => 'Year 5 Maths']);
    $gcse = ClassGroup::factory()->for($ada, 'tutor')->create(['name' => 'GCSE 100% Club']);
    $recent = inboxLesson('2026-10-13 09:00:00', group: $year5);
    inboxReport($recent, AttendeeRole::Student);
    $older = inboxLesson('2026-10-06 09:00:00', group: $gcse);
    inboxReport($older, AttendeeRole::Student, ['author_id' => User::factory()->student()->create(['name' => 'Zainab Bello'])->id]);

    expect(inboxIds('all', ['class' => $gcse->id]))->toBe([$older->id])
        ->and(inboxIds('all', ['tutor' => $tunde->id]))->toBe([$recent->id])
        ->and(inboxIds('all', ['from' => '2026-10-10']))->toBe([$recent->id])
        ->and(inboxIds('all', ['to' => '2026-10-07']))->toBe([$older->id])
        ->and(inboxIds('all', ['search' => 'YEAR 5']))->toBe([$recent->id])
        ->and(inboxIds('all', ['search' => 'ada']))->toBe([$older->id])
        ->and(inboxIds('all', ['search' => 'zainab']))->toBe([$older->id])
        ->and(inboxIds('all', ['search' => '%']))->toBe([$older->id]);

    expect($this->inbox->counts($this->inbox->filters(['class' => $year5->id, 'queue' => 'all'])))
        ->toBe(['review' => 1, 'raised' => 0, 'missing' => 0, 'reviewed' => 0, 'all' => 1]);

    expect($this->inbox->filters(['queue' => 'nope', 'class' => 'abc', 'tutor' => '-3', 'from' => 'yesterday', 'search' => ['x']]))
        ->toMatchArray(['queue' => 'review', 'class' => null, 'tutor' => null, 'from' => null, 'to' => null, 'search' => '']);
});

it('labels each row with its most important state and a friendly day', function () {
    $raised = inboxLesson('2026-10-13 09:00:00');
    inboxReport($raised, AttendeeRole::Tutor);
    RaisedIssue::factory()->for($raised)->create();

    $pending = inboxLesson('2026-10-14 09:00:00');
    $missing = inboxLesson('2026-10-11 09:00:00');

    $done = inboxLesson('2026-10-13 07:00:00');
    inboxReport($done, AttendeeRole::Tutor);
    inboxReviewed($done, '2026-10-13 12:00:00');

    $both = inboxLesson('2026-10-12 09:00:00');
    inboxReport($both, AttendeeRole::Tutor);
    inboxReport($both, AttendeeRole::Student);

    $tutorOnly = inboxLesson('2026-10-12 11:00:00');
    inboxReport($tutorOnly, AttendeeRole::Tutor);

    $running = inboxLesson('2026-10-14 11:30:00');
    inboxReport($running, AttendeeRole::Student, ['submitted_at' => now()]);
    inboxReport($running, AttendeeRole::Student, ['submitted_at' => now()]);

    $quiet = inboxLesson('2026-10-14 11:45:00');
    $lastYear = inboxLesson('2025-10-08 09:00:00');
    inboxReport($lastYear, AttendeeRole::Tutor);

    expect(inboxRow($raised))->toMatchArray(['state' => 'raised', 'stateLabel' => 'Raised with tutor', 'day' => 'Yesterday', 'time' => '10:00', 'className' => 'Year 5 Maths'])
        ->and(inboxRow($pending))->toMatchArray(['state' => 'pending', 'stateLabel' => 'Tutor report pending', 'day' => 'Today'])
        ->and(inboxRow($missing))->toMatchArray(['state' => 'missing', 'stateLabel' => 'Tutor report missing', 'day' => 'Sun 11 Oct'])
        ->and(inboxRow($done))->toMatchArray(['state' => 'reviewed', 'stateLabel' => 'Reviewed'])
        ->and(inboxRow($both))->toMatchArray(['state' => 'both', 'stateLabel' => 'Both sides submitted'])
        ->and(inboxRow($tutorOnly))->toMatchArray(['state' => 'tutor', 'stateLabel' => 'Tutor report only'])
        ->and(inboxRow($running))->toMatchArray(['state' => 'students', 'stateLabel' => '2 student reports'])
        ->and(inboxRow($quiet))->toMatchArray(['state' => 'waiting', 'stateLabel' => 'No reports yet'])
        ->and(inboxRow($lastYear)['day'])->toBe('Wed 8 Oct 2025');
});

it('builds the detail with the tutor report, students needing attention first and a summary', function () {
    $lesson = inboxLesson('2026-10-13 09:00:00');
    inboxAttend($lesson);
    inboxAttend($lesson, AttendanceStatus::Absent);
    inboxReport($lesson, AttendeeRole::Tutor, ['rating' => 4, 'went_well' => 'Strong session', 'issues' => 'Tolu arrived late', 'recommendations' => 'Set the place value quiz']);
    inboxReport($lesson, AttendeeRole::Student, ['rating' => 5, 'issues' => null, 'went_well' => 'Fun', 'submitted_at' => CarbonImmutable::parse('2026-10-13 10:30:00', 'UTC')]);
    inboxReport($lesson, AttendeeRole::Student, ['rating' => 1, 'issues' => null, 'went_well' => 'Too hard', 'submitted_at' => CarbonImmutable::parse('2026-10-13 10:10:00', 'UTC')]);
    inboxReport($lesson, AttendeeRole::Student, ['rating' => 2, 'issues' => 'I came late', 'went_well' => null, 'submitted_at' => CarbonImmutable::parse('2026-10-13 10:20:00', 'UTC')]);
    inboxReviewed($lesson, '2026-10-13 12:00:00');
    $issue = RaisedIssue::factory()->for($lesson)->create(['note' => 'Please recap place value', 'raised_by_id' => $this->admin->id]);

    $detail = $this->inbox->detail($lesson->fresh());

    expect($detail)->toMatchArray([
        'id' => $lesson->id,
        'className' => 'Year 5 Maths',
        'when' => 'Yesterday 10:00',
        'attendance' => '1 of 2 present',
        'studentSummary' => ['count' => 3, 'averageRating' => 2.7, 'needsAttention' => 2],
        'reviewed' => ['at' => '13 Oct, 13:00', 'by' => 'Academy Admin'],
    ])
        ->and($detail['tutorReport'])->toMatchArray(['rating' => 4, 'wentWell' => 'Strong session', 'issues' => 'Tolu arrived late', 'recommendations' => 'Set the place value quiz'])
        ->and(array_column($detail['studentReports'], 'rating'))->toBe([2, 1, 5])
        ->and(array_column($detail['studentReports'], 'needsAttention'))->toBe([true, true, false])
        ->and($detail['raisedIssues'])->toHaveCount(1)
        ->and($detail['raisedIssues'][0])->toMatchArray(['note' => 'Please recap place value', 'raisedBy' => 'Academy Admin', 'acknowledgedAt' => null]);

    $issue->update(['acknowledged_at' => now()]);

    expect($this->inbox->detail($lesson->fresh())['raisedIssues'][0]['acknowledgedAt'])->toBe('14 Oct, 13:00');
});

it('does not chase tutor reports for classes nobody attended', function () {
    $noShow = inboxLesson('2026-10-13 09:00:00');
    $noShow->classGroup->enrol(User::factory()->student()->create());

    $ran = inboxLesson('2026-10-12 09:00:00');
    inboxAttend($ran, AttendanceStatus::Late);

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($noShow->attendanceRecords()->count())->toBeGreaterThan(0)
        ->and(inboxIds('missing'))->toBe([$ran->id])
        ->and(inboxIds('all'))->toBe([$ran->id]);
});
