<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\Question;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    // Wednesday 15 October 2025, 12:00 in Lagos.
    $this->travelTo(CarbonImmutable::parse('2025-10-15 11:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['name' => 'Joy Great']);
});

it('shows an empty dashboard when nothing has happened yet', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/admin')
            ->where('header.date', 'Wednesday 15 October 2025')
            ->where('header.greeting', 'Good afternoon, Joy')
            ->where('stats.classesToday', 0)
            ->where('stats.attendance', null)
            ->where('stats.attendanceChange', null)
            ->where('stats.late', 0)
            ->has('today', 0)
            ->has('lateness', 0)
            ->has('repeatLateness', 0)
            ->where('trend.label', 'Last 6 weeks')
            ->has('trend.buckets', 6)
            ->where('trend.buckets.5.students', null)
            ->has('latestReports', 0)
            ->where('quizzes.questionBank', 0)
            ->has('quizzes.categories', 5));
});

it('summarises today for students and tutors alike', function () {
    $tutor = User::factory()->tutor()->create(['name' => 'Kofi Mensah']);
    $ava = User::factory()->student(YearGroup::Year5)->create();
    $ben = User::factory()->student(YearGroup::Year8)->create();
    $group = ClassGroup::factory()->for($tutor, 'tutor')->create(['name' => 'Year 5 Maths']);
    $group->enrol($ava);
    $group->enrol($ben);

    $lessonAt = fn (string $utcStart, int $minutes = 60, LessonStatus $status = LessonStatus::Scheduled) => Lesson::factory()->for($group)->create([
        'starts_at' => CarbonImmutable::parse($utcStart, 'UTC'),
        'ends_at' => CarbonImmutable::parse($utcStart, 'UTC')->addMinutes($minutes),
        'status' => $status,
    ]);

    $finished = $lessonAt('2025-10-15 08:00:00');                           // 09:00 Lagos, over
    $lessonAt('2025-10-15 10:30:00', 90);                                   // 11:30, in progress
    $lessonAt('2025-10-15 13:00:00', 60, LessonStatus::Cancelled);          // 14:00, cancelled
    $lessonAt('2025-10-15 15:00:00');                                       // 16:00, upcoming

    $record = app(RecordAttendance::class);
    $record->handle($finished, $tutor, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-15 08:05:00', 'UTC'));
    $record->handle($finished, $ava, AttendanceStatus::Present, $tutor, CarbonImmutable::parse('2025-10-15 07:58:00', 'UTC'));
    $record->handle($finished, $ben, AttendanceStatus::Absent, $tutor);

    LessonReport::factory()->for($finished)->create(['went_well' => 'Strong on place value.']);
    LessonReport::factory()->for($finished)->create(['author_id' => $ava->id, 'author_role' => AttendeeRole::Student]);

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.classesToday', 3)
            ->where('stats.runningNow', 1)
            ->where('stats.toStart', 1)
            ->where('stats.attendance', 50)
            ->where('stats.late', 1)
            ->where('stats.lateTutors', 1)
            ->where('stats.lateStudents', 0)
            ->where('stats.activeStudents', 2)
            ->where('stats.activeTutors', 1)
            ->has('today', 4)
            ->where('today.0.time', '09:00')
            ->where('today.0.className', 'Year 5 Maths')
            ->where('today.0.tutor', 'Kofi Mensah')
            ->where('today.0.state', 'finished')
            ->where('today.0.tutorStatus', 'late')
            ->where('today.0.tutorMinutesLate', 5)
            ->where('today.0.present', 1)
            ->where('today.0.expected', 2)
            ->where('today.0.tutorReported', true)
            ->where('today.0.studentReports', 1)
            ->where('today.1.state', 'in_progress')
            ->where('today.1.tutorStatus', null)
            ->where('today.2.state', 'cancelled')
            ->where('today.3.state', 'upcoming')
            ->has('lateness', 1)
            ->where('lateness.0.name', 'Kofi Mensah')
            ->where('lateness.0.role', 'tutor')
            ->where('lateness.0.arrived', '09:05')
            ->where('lateness.0.minutesLate', 5)
            ->where('trend.buckets.5.label', '13 Oct')
            ->where('trend.buckets.5.students', 100)
            ->where('trend.buckets.5.tutors', 0)
            ->where('trend.buckets.0.students', null)
            ->has('latestReports', 2)
            ->where('latestReports.0.role', 'student')
            ->where('latestReports.1.role', 'tutor')
            ->where('latestReports.1.wentWell', 'Strong on place value.')
            ->where('quizzes.categories.0.label', 'KS2')
            ->where('quizzes.categories.4.label', 'GCSE'));
});

it('compares student attendance with the same point last week', function () {
    $student = User::factory()->student()->create();
    $group = ClassGroup::factory()->create();
    $group->enrol($student);
    $record = app(RecordAttendance::class);

    // Last week (Wed 8 Oct): absent. This week (Mon 13 Oct): present.
    $lastWeek = Lesson::factory()->for($group)->create(['starts_at' => CarbonImmutable::parse('2025-10-08 08:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2025-10-08 09:00:00', 'UTC')]);
    $thisWeek = Lesson::factory()->for($group)->create(['starts_at' => CarbonImmutable::parse('2025-10-13 08:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2025-10-13 09:00:00', 'UTC')]);
    $record->handle($lastWeek, $student, AttendanceStatus::Absent, null);
    $record->handle($thisWeek, $student, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-13 07:55:00', 'UTC'));

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.attendance', 100)
            ->where('stats.attendanceChange', 100));
});

it('counts only published questions in the question bank', function () {
    Question::factory()->published()->count(2)->create();
    Question::factory()->published()->create()->delete();
    Question::factory()->inReview()->create();
    Question::factory()->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('quizzes.questionBank', 2));
});
