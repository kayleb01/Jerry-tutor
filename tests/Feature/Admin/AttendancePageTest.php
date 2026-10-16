<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->year8 = ClassGroup::factory()->create(['name' => 'Year 8 · Algebra', 'year_group' => YearGroup::Year8]);
    $this->year4 = ClassGroup::factory()->create(['name' => 'Year 4 · Number', 'year_group' => YearGroup::Year4]);

    $this->lesson = fn (ClassGroup $group, string $start, array $extra = []) => Lesson::factory()->for($group)->create([
        'tutor_id' => $group->tutor_id,
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour(),
        ...$extra,
    ]);

    $this->record = function (Lesson $lesson, string $name, AttendanceStatus $status, ?int $minutesLate = null, AttendeeRole $role = AttendeeRole::Student) {
        $user = $role === AttendeeRole::Tutor
            ? tap($lesson->tutor)->update(['name' => $name])
            : User::factory()->student()->create(['name' => $name]);

        return AttendanceRecord::factory()->create([
            'lesson_id' => $lesson->id,
            'user_id' => $user->id,
            'attendee_role' => $role,
            'status' => $status,
            'minutes_late' => $minutesLate,
            'arrived_at' => in_array($status, [AttendanceStatus::Absent, AttendanceStatus::Excused], true) ? null : $lesson->starts_at->addMinutes($minutesLate ?? -3),
        ]);
    };
});

it('shows the week\'s totals and every record, newest lesson first with the tutor at the top', function () {
    $algebra = ($this->lesson)($this->year8, '2026-10-13 14:00:00');
    ($this->record)($algebra, 'Sade Eze', AttendanceStatus::Late, 4);
    ($this->record)($algebra, 'Chidi Obi', AttendanceStatus::Present);
    ($this->record)($algebra, 'Mr K. Mensah', AttendanceStatus::Late, 5, AttendeeRole::Tutor);
    $number = ($this->lesson)($this->year4, '2026-10-12 13:00:00');
    ($this->record)($number, 'Zainab Kareem', AttendanceStatus::Absent);
    ($this->record)($number, 'Tolu Ade', AttendanceStatus::Excused);
    ($this->record)(($this->lesson)($this->year4, '2026-10-05 13:00:00'), 'Last Week', AttendanceStatus::Late, 30);

    $this->actingAs($this->admin)->get(route('admin.attendance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/attendance/index')
            ->where('filters.period', 'week')
            ->where('filters.stage', null)
            ->where('summary', [
                'expected' => 5, 'present' => 3, 'absent' => 2, 'absentShare' => 40, 'withReason' => 1,
                'late' => 2, 'lateStudents' => 1, 'lateTutors' => 1, 'averageMinutesLate' => 5,
            ])
            ->where('records.total', 5)
            ->where('records.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Mr K. Mensah', 'Chidi Obi', 'Sade Eze', 'Tolu Ade', 'Zainab Kareem'])
            ->where('records.data.0', fn ($row) => $row['role'] === 'tutor' && $row['className'] === 'Year 8 · Algebra' && $row['date'] === 'Tue 13 Oct'
                && $row['scheduled'] === '15:00' && $row['arrived'] === '15:05' && $row['status'] === 'late' && $row['minutesLate'] === 5)
            ->where('records.data.4.arrived', null)
            ->where('records.data.4.status', 'absent'));
});

it('narrows the records and totals to a stage, a class and students or tutors', function () {
    $algebra = ($this->lesson)($this->year8, '2026-10-13 14:00:00');
    ($this->record)($algebra, 'Sade Eze', AttendanceStatus::Late, 4);
    ($this->record)($algebra, 'Mr K. Mensah', AttendanceStatus::Present, null, AttendeeRole::Tutor);
    ($this->record)(($this->lesson)($this->year4, '2026-10-12 13:00:00'), 'Tolu Ade', AttendanceStatus::Present);
    ($this->record)(($this->lesson)(ClassGroup::factory()->create(['year_group' => null]), '2026-10-12 15:00:00'), 'Eleven Plus', AttendanceStatus::Present);

    $names = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.attendance.index', $query));

    $names(['stage' => 'ks3'])->assertInertia(fn (Assert $page) => $page
        ->where('filters.stage', 'ks3')
        ->where('records.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Mr K. Mensah', 'Sade Eze'])
        ->where('summary.expected', 2));
    $names(['stage' => 'ks3', 'role' => 'student'])->assertInertia(fn (Assert $page) => $page->where('records.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Sade Eze'])->where('summary.lateTutors', 0));
    $names(['class' => $this->year4->id])->assertInertia(fn (Assert $page) => $page->where('records.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Tolu Ade']));
    $names(['stage' => 'nonsense'])->assertInertia(fn (Assert $page) => $page->where('filters.stage', null)->where('records.total', 4));
});

it('shows ten records a page', function () {
    $lesson = ($this->lesson)($this->year8, '2026-10-13 14:00:00');
    foreach (range(1, 11) as $number) {
        ($this->record)($lesson, sprintf('Student %02d', $number), AttendanceStatus::Present);
    }

    $this->actingAs($this->admin)->get(route('admin.attendance.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('records.last_page', 2)->where('records.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Student 11']));
});

it('offers today\'s running lessons for opening a register', function () {
    ($this->lesson)($this->year4, '2026-10-15 15:00:00', ['status' => LessonStatus::Cancelled]);
    $running = ($this->lesson)($this->year8, '2026-10-15 14:00:00');
    ($this->lesson)($this->year8, '2026-10-16 14:00:00');

    $this->actingAs($this->admin)->get(route('admin.attendance.index'))
        ->assertInertia(fn (Assert $page) => $page->where('todayLessons', [['id' => $running->id, 'time' => '15:00', 'className' => 'Year 8 · Algebra']]));
});

it('exports the filtered records as CSV, with formulas made harmless', function () {
    $algebra = ($this->lesson)($this->year8, '2026-10-13 14:00:00');
    ($this->record)($algebra, '=HYPERLINK("x")', AttendanceStatus::Late, 4);
    ($this->record)(($this->lesson)($this->year4, '2026-10-12 13:00:00'), 'Tolu Ade', AttendanceStatus::Absent);

    $csv = $this->actingAs($this->admin)->get(route('admin.attendance.export', ['stage' => 'ks3']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    expect($csv)
        ->toContain('Date,Name,Role,Class,"Scheduled start (Africa/Lagos)","Arrived (Africa/Lagos)",Attendance,"Minutes late",Notes')
        ->toContain("2026-10-13,\"'=HYPERLINK(\"\"x\"\")\",student,\"Year 8 · Algebra\",15:00,15:04,Late,4,")
        ->not->toContain('Tolu Ade');
});

it('is for admins only', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create())->get(route('admin.attendance.index'))->assertForbidden();
    $this->actingAs(User::factory()->{$role}()->create())->get(route('admin.attendance.export'))->assertForbidden();
})->with(['tutor', 'student', 'guardian']);
