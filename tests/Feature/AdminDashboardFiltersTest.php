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
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    // Wednesday 15 October 2025, 12:00 in Lagos.
    $this->travelTo(CarbonImmutable::parse('2025-10-15 11:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['name' => 'Joy Great']);

    $this->tutorA = User::factory()->tutor()->create(['name' => 'Ada Tutor']);
    $this->tutorB = User::factory()->tutor()->create(['name' => 'Bayo Tutor']);
    $this->groupA = ClassGroup::factory()->withSlot($this->tutorA)->create(['name' => 'Year 5 Maths']);
    $this->groupB = ClassGroup::factory()->withSlot($this->tutorB)->create(['name' => 'Year 8 Maths']);
    $this->ava = User::factory()->student()->create(['name' => 'Ava']);
    $this->ben = User::factory()->student()->create(['name' => 'Ben']);
    $this->groupA->enrol($this->ava);
    $this->groupB->enrol($this->ben);

    // Records a lesson on a given date (09:00 Lagos) with a student status; $late = minutes late.
    $this->lesson = function (ClassGroup $group, string $date, User $student, AttendanceStatus $status, int $late = 0): Lesson {
        $start = CarbonImmutable::parse("{$date} 08:00:00", 'UTC');
        $lesson = Lesson::factory()->for($group)->create(['starts_at' => $start, 'ends_at' => $start->addHour()]);
        app(RecordAttendance::class)->handle($lesson, $student, $status, null, $status === AttendanceStatus::Present ? $start->addMinutes($late) : null);

        return $lesson;
    };
});

it('defaults to this week and offers the filter options', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', 'week')
            ->where('filters.from', '2025-10-13')
            ->where('filters.to', '2025-10-15')
            ->where('filters.label', 'This week')
            ->where('filters.error', null)
            ->has('options.periods', 7)
            ->where('options.classes', [['value' => $this->groupA->id, 'label' => 'Year 5 Maths'], ['value' => $this->groupB->id, 'label' => 'Year 8 Maths']])
            ->where('options.tutors', [['value' => $this->tutorA->id, 'label' => 'Ada Tutor'], ['value' => $this->tutorB->id, 'label' => 'Bayo Tutor']]));
});

it('works out each preset period in academy time', function (string $period, string $from, string $to, string $label) {
    $this->actingAs($this->admin)->get(route('dashboard', ['period' => $period]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', $period)
            ->where('filters.from', $from)
            ->where('filters.to', $to)
            ->where('filters.label', $label));
})->with([
    ['today', '2025-10-15', '2025-10-15', 'Today'],
    ['last_7', '2025-10-09', '2025-10-15', 'Last 7 days'],
    ['month', '2025-10-01', '2025-10-15', 'This month'],
    ['last_30', '2025-09-16', '2025-10-15', 'Last 30 days'],
    ['last_90', '2025-07-18', '2025-10-15', 'Last 90 days'],
]);

it('filters analytics to the period: attendance, lateness and reports', function () {
    ($this->lesson)($this->groupA, '2025-10-14', $this->ava, AttendanceStatus::Present, 10); // this week, late
    ($this->lesson)($this->groupA, '2025-09-30', $this->ava, AttendanceStatus::Absent);      // two weeks ago
    $old = ($this->lesson)($this->groupB, '2025-09-29', $this->ben, AttendanceStatus::Present);
    LessonReport::factory()->for($old)->create(['went_well' => 'Old report']);

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.attendance', 100)
            ->where('stats.late', 1)
            ->has('lateness', 1)
            ->has('latestReports', 0));

    $this->actingAs($this->admin)->get(route('dashboard', ['period' => 'last_30']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.attendance', 67)
            ->has('latestReports', 1)
            ->where('latestReports.0.wentWell', 'Old report'));
});

it('accepts a custom date range and compares it with the period before', function () {
    ($this->lesson)($this->groupA, '2025-10-01', $this->ava, AttendanceStatus::Present);
    ($this->lesson)($this->groupA, '2025-10-02', $this->ava, AttendanceStatus::Present);
    ($this->lesson)($this->groupA, '2025-09-29', $this->ava, AttendanceStatus::Absent);   // the 3 days before

    $this->actingAs($this->admin)->get(route('dashboard', ['period' => 'custom', 'from' => '2025-10-01', 'to' => '2025-10-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', 'custom')
            ->where('filters.label', '1 – 3 Oct 2025')
            ->where('stats.attendance', 100)
            ->where('stats.attendanceChange', 100));
});

it('falls back to this week with a message when custom dates are unusable', function (array $query, string $message) {
    $this->actingAs($this->admin)->get(route('dashboard', ['period' => 'custom', ...$query]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', 'week')
            ->where('filters.error', $message));
})->with([
    'missing end' => [['from' => '2025-10-01'], 'Choose both a start and an end date.'],
    'back to front' => [['from' => '2025-10-10', 'to' => '2025-10-01'], 'The start date must be on or before the end date.'],
    'over a year' => [['from' => '2024-01-01', 'to' => '2025-10-01'], 'Choose a range of one year or less.'],
    'not a date' => [['from' => '2025-02-31', 'to' => 'soon'], 'Choose both a start and an end date.'],
]);

it('narrows everything to one class or one tutor, including today\'s classes', function () {
    ($this->lesson)($this->groupA, '2025-10-14', $this->ava, AttendanceStatus::Present, 5);
    ($this->lesson)($this->groupB, '2025-10-14', $this->ben, AttendanceStatus::Absent);
    Lesson::factory()->for($this->groupB)->create(['tutor_id' => $this->tutorB->id, 'starts_at' => CarbonImmutable::parse('2025-10-15 15:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2025-10-15 16:00:00', 'UTC')]);

    foreach ([['class' => $this->groupA->id], ['tutor' => $this->tutorA->id]] as $filter) {
        $this->actingAs($this->admin)->get(route('dashboard', $filter))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.attendance', 100)
                ->where('stats.late', 1)
                ->where('stats.activeStudents', 1)
                ->has('today', 0));
    }

    $this->actingAs($this->admin)->get(route('dashboard', ['class' => $this->groupB->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.attendance', 0)
            ->where('stats.late', 0)
            ->has('today', 1));
});

it('shows only students or only tutors when asked', function () {
    $lesson = ($this->lesson)($this->groupA, '2025-10-14', $this->ava, AttendanceStatus::Present, 5);
    app(RecordAttendance::class)->handle($lesson, $this->tutorA, AttendanceStatus::Present, null, $lesson->starts_at->addMinutes(8));
    LessonReport::factory()->for($lesson)->create();
    LessonReport::factory()->for($lesson)->create(['author_id' => $this->ava->id, 'author_role' => AttendeeRole::Student]);

    $this->actingAs($this->admin)->get(route('dashboard', ['role' => 'tutor']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.role', 'tutor')
            ->where('stats.late', 1)
            ->where('stats.lateTutors', 1)
            ->where('stats.lateStudents', 0)
            ->where('lateness.0.role', 'tutor')
            ->has('latestReports', 1)
            ->where('latestReports.0.role', 'tutor'));
});

it('groups the on-time trend by day, week or month to suit the period', function (array $query, string $label, int $buckets, string $first) {
    $this->actingAs($this->admin)->get(route('dashboard', $query))
        ->assertInertia(fn (Assert $page) => $page
            ->where('trend.label', $label)
            ->has('trend.buckets', $buckets)
            ->where('trend.buckets.0.label', $first));
})->with([
    'short periods show 6 weeks' => [['period' => 'week'], 'Last 6 weeks', 6, '8 Sep'],
    'a fortnight by day' => [['period' => 'custom', 'from' => '2025-10-02', 'to' => '2025-10-15'], 'By day', 14, '2 Oct'],
    '30 days by week' => [['period' => 'last_30'], 'By week', 5, '15 Sep'],
    'half a year by month' => [['period' => 'custom', 'from' => '2025-04-01', 'to' => '2025-09-30'], 'By month', 6, 'Apr 2025'],
]);

it('ignores filter values it does not understand', function () {
    $this->actingAs($this->admin)->get(route('dashboard', ['period' => 'forever', 'class' => 'abc', 'tutor' => '-1', 'role' => 'admin']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', 'week')
            ->where('filters.class', null)
            ->where('filters.tutor', null)
            ->where('filters.role', null));
});
