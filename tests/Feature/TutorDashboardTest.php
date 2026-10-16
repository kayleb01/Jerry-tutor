<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use App\Services\TutorDashboard;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    // Tuesday 13 October 2026, 15:56 in Lagos.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:56:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create(['name' => 'Mr Adeyemi']);
    $this->group = ClassGroup::factory()->create([
        'tutor_id' => $this->tutor->id,
        'name' => 'Year 5 Geometry',
        'year_group' => YearGroup::Year5,
        'location' => 'Room 2',
    ]);

    $this->lessonAt = function (string $startsAt, array $overrides = [], ?ClassGroup $group = null): Lesson {
        $group ??= $this->group;
        $start = CarbonImmutable::parse($startsAt, 'UTC');

        return Lesson::factory()->for($group)->create([
            'tutor_id' => $group->tutor_id,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            ...$overrides,
        ]);
    };
    $this->studentRecord = fn (Lesson $lesson, AttendanceStatus $status, ?int $recordedBy = 1) => AttendanceRecord::factory()->create([
        'lesson_id' => $lesson->id,
        'user_id' => User::factory()->student()->create()->id,
        'attendee_role' => AttendeeRole::Student,
        'status' => $status,
        'recorded_by_id' => $recordedBy,
    ]);
    $this->tutorRecord = fn (Lesson $lesson, AttendanceStatus $status, ?CarbonImmutable $arrivedAt = null, ?int $minutesLate = null) => AttendanceRecord::factory()->create([
        'lesson_id' => $lesson->id,
        'user_id' => $lesson->tutor_id,
        'attendee_role' => AttendeeRole::Tutor,
        'status' => $status,
        'arrived_at' => $arrivedAt ?? $lesson->starts_at,
        'minutes_late' => $minutesLate,
        'recorded_by_id' => $lesson->tutor_id,
    ]);
    $this->dashboard = fn (): array => app(TutorDashboard::class)->for($this->tutor);
});

it('greets the tutor and shows the next class with check-in', function () {
    $lesson = ($this->lessonAt)('2026-10-13 15:00:00');
    $this->group->enrol(User::factory()->student()->create());
    $this->group->enrol(User::factory()->student()->create());

    $props = ($this->dashboard)();

    expect($props['header'])->toBe(['date' => 'Tuesday 13 October 2026', 'greeting' => 'Good afternoon, Mr Adeyemi', 'now' => '15:56'])
        ->and($props['nextLesson'])->toBe([
            'id' => $lesson->id,
            'className' => 'Year 5 Geometry',
            'time' => '16:00 – 17:00',
            'location' => 'Room 2',
            'students' => 2,
            'startsAt' => '2026-10-13T15:00:00+00:00',
            'state' => 'upcoming',
            'isToday' => true,
            'canCheckIn' => true,
            'checkedInAt' => null,
            'minutesLate' => null,
        ]);
});

it('says good morning and good evening at the right hours', function (string $utc, string $greeting) {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

    expect(($this->dashboard)()['header']['greeting'])->toBe($greeting);
})->with([
    ['2026-10-13 10:59:00', 'Good morning, Mr Adeyemi'],
    ['2026-10-13 11:00:00', 'Good afternoon, Mr Adeyemi'],
    ['2026-10-13 15:59:00', 'Good afternoon, Mr Adeyemi'],
    ['2026-10-13 16:00:00', 'Good evening, Mr Adeyemi'],
]);

it('shows the next class as checked in once the tutor has arrived', function () {
    $lesson = ($this->lessonAt)('2026-10-13 14:50:00');
    ($this->tutorRecord)($lesson, AttendanceStatus::Late, CarbonImmutable::parse('2026-10-13 14:55:00', 'UTC'), 5);

    expect(($this->dashboard)()['nextLesson'])->toMatchArray([
        'id' => $lesson->id,
        'state' => 'in_progress',
        'isToday' => true,
        'canCheckIn' => false,
        'checkedInAt' => '15:55',
        'minutesLate' => 5,
    ]);
});

it('names the next class on a later day when none is left today', function () {
    ($this->lessonAt)('2026-10-13 09:00:00');
    $later = ($this->lessonAt)('2026-10-15 09:00:00');
    ($this->lessonAt)('2026-10-14 09:00:00', ['status' => LessonStatus::Cancelled]);
    $this->group->enrol(User::factory()->student()->create());

    expect(($this->dashboard)()['nextLesson'])->toMatchArray([
        'id' => $later->id,
        'time' => '10:00 – 11:00',
        'state' => 'upcoming',
        'isToday' => false,
        'canCheckIn' => false,
        'students' => 1,
    ]);
});

it('works out punctuality, classes this week, reports to submit and student attendance', function () {
    // Punctuality: three on time and one late in earlier weeks, plus noise that must not count.
    foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22'] as $index => $day) {
        $lesson = ($this->lessonAt)("{$day} 09:00:00");
        ($this->tutorRecord)($lesson, $index === 3 ? AttendanceStatus::Late : AttendanceStatus::Present, null, $index === 3 ? 9 : null);
        LessonReport::factory()->create(['lesson_id' => $lesson->id]);
    }
    ($this->tutorRecord)(($this->lessonAt)('2026-09-29 09:00:00', ['status' => LessonStatus::Cancelled]), AttendanceStatus::Absent);
    $tooOld = ($this->lessonAt)('2026-06-01 09:00:00');
    ($this->tutorRecord)($tooOld, AttendanceStatus::Late, null, 12);
    LessonReport::factory()->create(['lesson_id' => $tooOld->id]);

    // This week: Mon and Tue morning are done, Tue afternoon and Wed are to go, Thu is cancelled.
    $monday = ($this->lessonAt)('2026-10-12 09:00:00');
    $tuesday = ($this->lessonAt)('2026-10-13 09:00:00');
    ($this->lessonAt)('2026-10-13 15:00:00');
    ($this->lessonAt)('2026-10-14 09:00:00');
    ($this->lessonAt)('2026-10-15 09:00:00', ['status' => LessonStatus::Cancelled]);

    // Student attendance (excused ignored, system-made records ignored): 4 of 5 present or late.
    foreach ([AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Absent, AttendanceStatus::Excused] as $status) {
        ($this->studentRecord)($monday, $status);
    }
    ($this->studentRecord)($tuesday, AttendanceStatus::Late);
    ($this->studentRecord)($tuesday, AttendanceStatus::Absent, null);

    $stats = ($this->dashboard)()['stats'];

    expect($stats['punctuality'])->toBe(['percent' => 75, 'onTime' => 3, 'attended' => 4])
        ->and($stats['week'])->toBe(['total' => 4, 'done' => 2, 'toGo' => 2])
        ->and($stats['reports'])->toBe(['count' => 2, 'oldest' => 'Year 5 · Geometry (Monday)'])
        ->and($stats['studentAttendance'])->toBe(80);
});

it('dates the oldest report by day and month once it is more than a week old', function () {
    $this->group->update(['name' => 'Year 6 Algebra', 'year_group' => YearGroup::Year6]);
    $lesson = ($this->lessonAt)('2026-10-02 09:00:00');
    ($this->studentRecord)($lesson, AttendanceStatus::Present);

    expect(($this->dashboard)()['stats']['reports'])->toBe(['count' => 1, 'oldest' => 'Year 6 · Algebra (2 Oct)']);
});

it('lists this week\'s classes Monday to Sunday with their state, summary and report status', function () {
    $this->group->enrol(User::factory()->student()->create());
    $this->group->enrol(User::factory()->student()->create());

    ($this->lessonAt)('2026-10-11 09:00:00'); // last Sunday
    ($this->lessonAt)('2026-10-19 09:00:00'); // next Monday

    $mondayEarly = ($this->lessonAt)('2026-10-12 07:00:00');
    ($this->studentRecord)($mondayEarly, AttendanceStatus::Present);
    ($this->studentRecord)($mondayEarly, AttendanceStatus::Late);
    ($this->studentRecord)($mondayEarly, AttendanceStatus::Absent);
    ($this->tutorRecord)($mondayEarly, AttendanceStatus::Present);
    LessonReport::factory()->create(['lesson_id' => $mondayEarly->id]);

    $mondayLate = ($this->lessonAt)('2026-10-12 12:00:00');
    ($this->studentRecord)($mondayLate, AttendanceStatus::Present);
    ($this->tutorRecord)($mondayLate, AttendanceStatus::Late, null, 7);
    LessonReport::factory()->draft()->create(['lesson_id' => $mondayLate->id]);

    $tuesdayEarly = ($this->lessonAt)('2026-10-13 05:00:00');
    ($this->studentRecord)($tuesdayEarly, AttendanceStatus::Present);

    $tuesdayEmpty = ($this->lessonAt)('2026-10-13 07:00:00');
    ($this->studentRecord)($tuesdayEmpty, AttendanceStatus::Absent, null);

    $running = ($this->lessonAt)('2026-10-13 14:30:00');
    $soon = ($this->lessonAt)('2026-10-13 15:00:00');
    $wednesday = ($this->lessonAt)('2026-10-14 09:00:00');
    $thursday = ($this->lessonAt)('2026-10-15 09:00:00', ['status' => LessonStatus::Cancelled]);
    $sunday = ($this->lessonAt)('2026-10-18 09:00:00');

    $week = ($this->dashboard)()['week'];

    expect(array_column($week, 'id'))->toBe([
        $mondayEarly->id, $mondayLate->id, $tuesdayEarly->id, $tuesdayEmpty->id, $running->id, $soon->id, $wednesday->id, $thursday->id, $sunday->id,
    ]);

    $row = fn (Lesson $lesson): array => collect($week)->firstWhere('id', $lesson->id);

    expect($row($mondayEarly))->toBe(['id' => $mondayEarly->id, 'day' => 'Mon', 'time' => '08:00 – 09:00', 'className' => 'Year 5 Geometry', 'state' => 'past', 'summary' => '2 of 3 present · you were on time', 'report' => 'submitted', 'canOpenRegister' => false])
        ->and($row($mondayLate))->toMatchArray(['summary' => '1 of 1 present · you were 7 min late', 'report' => 'draft', 'state' => 'past'])
        ->and($row($tuesdayEarly))->toMatchArray(['day' => 'Today', 'state' => 'past', 'summary' => "1 of 1 present · you didn't check in", 'report' => 'needed', 'canOpenRegister' => false])
        ->and($row($tuesdayEmpty))->toMatchArray(['state' => 'past', 'summary' => 'Register not taken', 'report' => null])
        ->and($row($running))->toMatchArray(['day' => 'Today', 'state' => 'today', 'summary' => 'In progress', 'report' => null, 'canOpenRegister' => true])
        ->and($row($soon))->toMatchArray(['state' => 'today', 'summary' => 'Starts in 4 minutes', 'canOpenRegister' => true])
        ->and($row($wednesday))->toMatchArray(['day' => 'Wed', 'state' => 'upcoming', 'summary' => '2 students', 'canOpenRegister' => false])
        ->and($row($thursday))->toMatchArray(['day' => 'Thu', 'state' => 'cancelled', 'summary' => 'Cancelled', 'report' => null, 'canOpenRegister' => false])
        ->and($row($sunday))->toMatchArray(['day' => 'Sun', 'state' => 'upcoming']);
});

it('renders for a tutor with no classes at all', function () {
    $props = ($this->dashboard)();

    expect($props['nextLesson'])->toBeNull()
        ->and($props['week'])->toBe([])
        ->and($props['stats'])->toBe([
            'punctuality' => ['percent' => null, 'onTime' => 0, 'attended' => 0],
            'week' => ['total' => 0, 'done' => 0, 'toGo' => 0],
            'reports' => ['count' => 0, 'oldest' => null],
            'studentAttendance' => null,
        ])
        ->and($props['studentsToWatch'])->toBe([]);
});

it('shows only the tutor\'s own classes', function () {
    $otherGroup = ClassGroup::factory()->create(['name' => 'Year 4 Fractions']);
    $theirs = ($this->lessonAt)('2026-10-13 15:00:00', [], $otherGroup);
    ($this->studentRecord)($theirs, AttendanceStatus::Present);
    ($this->tutorRecord)(($this->lessonAt)('2026-10-12 09:00:00', [], $otherGroup), AttendanceStatus::Present);
    $mine = ($this->lessonAt)('2026-10-14 09:00:00');

    $props = ($this->dashboard)();

    expect(array_column($props['week'], 'id'))->toBe([$mine->id])
        ->and($props['nextLesson']['id'])->toBe($mine->id)
        ->and($props['stats']['week']['total'])->toBe(1)
        ->and($props['stats']['reports']['count'])->toBe(0)
        ->and($props['stats']['punctuality']['attended'])->toBe(0)
        ->and($props['stats']['studentAttendance'])->toBeNull();
});

it('is what the tutor sees on the dashboard route', function () {
    ($this->lessonAt)('2026-10-13 15:00:00');

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/tutor')
            ->where('header.greeting', 'Good afternoon, Mr Adeyemi')
            ->has('week', 1)
            ->has('fromAcademy')
            ->missing('reportsDue'));
});
