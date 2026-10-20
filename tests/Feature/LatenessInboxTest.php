<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\GuardianNotice;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LatenessInbox;
use App\Services\LatenessReportQuery;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->group = ClassGroup::factory()->withSlot()->create(['name' => 'Year 4 Number']);
    $this->tolu = User::factory()->student(YearGroup::Year4)->create(['name' => 'Tolu Ade']);
    $this->group->enrol($this->tolu);
});

function lateFor(User $who, string $date, int $minutes = 12, ?ClassGroup $group = null): LatenessReport
{
    $group ??= test()->group;
    $startsAt = CarbonImmutable::parse("{$date} 13:00:00", 'UTC');
    $lesson = Lesson::factory()->for($group)->create(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);

    return app(RecordAttendance::class)->handle($lesson, $who, AttendanceStatus::Present, null, $startsAt->addMinutes($minutes))->latenessReport;
}

it('stores notices to guardians against a lateness report', function () {
    $report = lateFor($this->tolu, '2026-10-13');
    $notice = GuardianNotice::factory()->create(['student_id' => $this->tolu->id, 'lateness_report_id' => $report->id]);

    expect($report->guardianNotices)->toHaveCount(1)
        ->and($notice->student->is($this->tolu))->toBeTrue()
        ->and($notice->acknowledged_at)->toBeNull()
        ->and(GuardianNotice::factory()->acknowledged()->create()->acknowledged_at)->toBeInstanceOf(CarbonImmutable::class);
});

function latenessIds(array $filters = []): array
{
    $query = app(LatenessReportQuery::class);

    return $query->filtered($query->filters($filters))->pluck('id')->all();
}

it('splits reports into new and followed up, combined with the other filters', function () {
    $new = lateFor($this->tolu, '2026-10-13');
    $followed = lateFor($this->tolu, '2026-10-12');
    $followed->update(['reviewed_at' => now(), 'follow_up_notes' => 'Spoke to mum.']);
    $tutorLate = lateFor($this->group->slots()->first()->tutor, '2026-10-11', 5);
    $query = app(LatenessReportQuery::class);

    expect(latenessIds())->toBe([$new->id, $tutorLate->id])
        ->and(latenessIds(['status' => 'followed']))->toBe([$followed->id])
        ->and(latenessIds(['status' => 'all']))->toBe([$new->id, $followed->id, $tutorLate->id])
        ->and(latenessIds(['status' => 'nope']))->toBe([$new->id, $tutorLate->id])
        ->and(latenessIds(['status' => 'new', 'role' => 'tutor']))->toBe([$tutorLate->id])
        ->and(app(LatenessInbox::class)->counts($query->filters(['role' => 'student', 'status' => 'all'])))
        ->toBe(['new' => 1, 'followed' => 1, 'all' => 2]);
});

it('shows each report as a row', function () {
    $report = lateFor($this->tolu, '2026-10-13');
    $lastYear = lateFor($this->tolu, '2025-10-08');

    expect(app(LatenessInbox::class)->row($report))->toBe([
        'id' => $report->id,
        'name' => 'Tolu Ade',
        'role' => 'student',
        'className' => 'Year 4 Number',
        'date' => 'Tue 13 Oct',
        'scheduled' => '14:00',
        'arrived' => '14:12',
        'minutesLate' => 12,
        'status' => 'new',
        'statusLabel' => 'New',
    ])->and(app(LatenessInbox::class)->row($lastYear)['date'])->toBe('Wed 8 Oct 2025');
});

it('gives the person their recent history and repeat count', function () {
    $report = lateFor($this->tolu, '2026-10-13');
    foreach (['2026-10-06', '2026-09-29', '2026-09-15', '2026-09-13', '2026-07-01'] as $date) {
        lateFor($this->tolu, $date, 6);
    }
    $other = User::factory()->student()->create();
    $this->group->enrol($other);
    lateFor($other, '2026-10-10');

    $detail = app(LatenessInbox::class)->detail($report);

    expect($detail)->toMatchArray([
        'name' => 'Tolu Ade',
        'roleLabel' => 'Student',
        'yearGroup' => 'Year 4',
        'className' => 'Year 4 Number',
        'date' => 'Tue 13 Oct',
        'scheduled' => '14:00',
        'arrived' => '14:12',
        'minutesLate' => 12,
        'followedUp' => null,
        'repeatCount' => 4,
    ])
        ->and(array_column($detail['history']['items'], 'date'))->toBe(['Tue 6 Oct', 'Tue 29 Sep', 'Tue 15 Sep', 'Sun 13 Sep'])
        ->and($detail['history']['items'][0])->toBe(['date' => 'Tue 6 Oct', 'className' => 'Year 4 Number', 'minutesLate' => 6])
        ->and($detail['history']['total'])->toBe(5);
});

it('only offers to notify a parent for students with a guardian', function () {
    $tutorReport = lateFor($this->group->slots()->first()->tutor, '2026-10-12', 5);
    $report = lateFor($this->tolu, '2026-10-13');
    $inbox = app(LatenessInbox::class);

    expect($inbox->detail($tutorReport))->toMatchArray(['canNotifyParent' => false, 'guardians' => [], 'yearGroup' => null])
        ->and($inbox->detail($report))->toMatchArray(['canNotifyParent' => false, 'guardians' => []]);

    User::factory()->guardian()->create(['name' => 'Mrs Ade'])->linkChild($this->tolu, 'Mother');
    $admin = User::factory()->admin()->create(['name' => 'Mrs J. Great']);
    GuardianNotice::factory()->create(['student_id' => $this->tolu->id, 'lateness_report_id' => $report->id, 'sent_by_id' => $admin->id, 'note' => 'Tolu was late.']);

    $detail = $inbox->detail($report->fresh());

    expect($detail)->toMatchArray([
        'canNotifyParent' => true,
        'guardians' => ['Mrs Ade'],
        'parentMessage' => 'Tolu arrived 12 minutes late to Year 4 Number on Tuesday 13 October (the class started at 14:00).',
    ])
        ->and($detail['notices'][0])->toMatchArray(['note' => 'Tolu was late.', 'sentBy' => 'Mrs J. Great', 'acknowledgedAt' => null, 'acknowledgedBy' => null]);
});
