<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\YearGroup;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Strand;
use App\Models\User;
use App\Services\StudentsToWatch;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create();
    $this->group = ClassGroup::factory()->create(['tutor_id' => $this->tutor->id]);
});

function watchedStudent(ClassGroup $group, string $name, YearGroup $year = YearGroup::Year5): User
{
    $student = User::factory()->student($year)->create(['name' => $name]);
    $group->enrol($student);

    return $student;
}

function markStudent(ClassGroup $group, User $student, AttendanceStatus $status, string $daysAgo, ?string $note = null, bool $byPerson = true): void
{
    $start = CarbonImmutable::now()->subDays((int) $daysAgo);
    $lesson = Lesson::query()->firstOrCreate(
        ['class_group_id' => $group->id, 'starts_at' => $start],
        Lesson::factory()->raw(['class_group_id' => $group->id, 'starts_at' => $start, 'ends_at' => $start->addHour()]),
    );

    AttendanceRecord::factory()->create([
        'lesson_id' => $lesson->id,
        'user_id' => $student->id,
        'attendee_role' => AttendeeRole::Student,
        'status' => $status,
        'notes' => $note,
        'recorded_by_id' => $byPerson ? $group->tutor_id : null,
    ]);
}

function takeQuizzes(User $student, Strand $strand, int $count, int $score): void
{
    $quiz = Quiz::factory()->create(['strand_id' => $strand->id]);
    QuizAttempt::factory()->count($count)->submitted($score)->create(['user_id' => $student->id, 'quiz_id' => $quiz->id]);
}

function watchList(User $tutor): array
{
    return app(StudentsToWatch::class)->for($tutor);
}

it('flags a student absent from their last class with the register note', function () {
    $ava = watchedStudent($this->group, 'Ava');
    markStudent($this->group, $ava, AttendanceStatus::Present, '9');
    markStudent($this->group, $ava, AttendanceStatus::Absent, '2', 'asked for quieter seating');
    $ben = watchedStudent($this->group, 'Ben');
    markStudent($this->group, $ben, AttendanceStatus::Absent, '9');
    markStudent($this->group, $ben, AttendanceStatus::Present, '2');
    $cal = watchedStudent($this->group, 'Cal');
    markStudent($this->group, $cal, AttendanceStatus::Absent, '9');
    markStudent($this->group, $cal, AttendanceStatus::Absent, '2');

    expect(watchList($this->tutor))->toBe([
        ['id' => $cal->id, 'name' => 'Cal', 'yearGroup' => 'Year 5', 'reasons' => 'Absent last class (2 times in 90 days)'],
        ['id' => $ava->id, 'name' => 'Ava', 'yearGroup' => 'Year 5', 'reasons' => 'Absent last class · asked for quieter seating'],
    ]);
});

it('flags repeated lateness and absence only from twice upwards', function () {
    $once = watchedStudent($this->group, 'Once');
    markStudent($this->group, $once, AttendanceStatus::Late, '5');
    $twice = watchedStudent($this->group, 'Twice');
    markStudent($this->group, $twice, AttendanceStatus::Late, '12');
    markStudent($this->group, $twice, AttendanceStatus::Late, '5');
    $thrice = watchedStudent($this->group, 'Thrice');
    foreach (['20', '12', '5'] as $days) {
        markStudent($this->group, $thrice, AttendanceStatus::Late, $days);
    }
    $absent = watchedStudent($this->group, 'Absent');
    markStudent($this->group, $absent, AttendanceStatus::Absent, '20');
    markStudent($this->group, $absent, AttendanceStatus::Absent, '12');
    markStudent($this->group, $absent, AttendanceStatus::Present, '5');

    $byName = collect(watchList($this->tutor))->pluck('reasons', 'name')->all();

    expect($byName)->toBe([
        'Thrice' => 'Late 3 times in 90 days',
        'Absent' => 'Absent 2 times in 90 days',
        'Twice' => 'Late twice in 90 days',
    ]);
});

it('ignores automatic no-show absences and anything older than 90 days', function () {
    $ava = watchedStudent($this->group, 'Ava');
    markStudent($this->group, $ava, AttendanceStatus::Absent, '3', byPerson: false);
    markStudent($this->group, $ava, AttendanceStatus::Absent, '4', byPerson: false);
    $ben = watchedStudent($this->group, 'Ben');
    markStudent($this->group, $ben, AttendanceStatus::Absent, '100');
    markStudent($this->group, $ben, AttendanceStatus::Absent, '95');

    expect(watchList($this->tutor))->toBe([]);
});

it('flags a student doing well in a topic after three quizzes at 85% or more', function () {
    $geometry = Strand::factory()->create(['name' => 'Geometry']);
    $ava = watchedStudent($this->group, 'Ava');
    takeQuizzes($ava, $geometry, 3, 9);
    $ben = watchedStudent($this->group, 'Ben');
    takeQuizzes($ben, $geometry, 2, 10);
    $cal = watchedStudent($this->group, 'Cal');
    takeQuizzes($cal, $geometry, 3, 8);
    $dee = watchedStudent($this->group, 'Dee', YearGroup::Year10);
    takeQuizzes($dee, $geometry, 4, 9);
    $old = watchedStudent($this->group, 'Old');
    takeQuizzes($old, $geometry, 3, 10);
    QuizAttempt::query()->where('user_id', $old->id)->update(['submitted_at' => now()->subDays(100)]);
    QuizAttempt::factory()->create(['user_id' => $ava->id, 'quiz_id' => Quiz::factory()->create(['strand_id' => $geometry->id])->id]);

    expect(watchList($this->tutor))->toBe([
        ['id' => $ava->id, 'name' => 'Ava', 'yearGroup' => 'Year 5', 'reasons' => 'Geometry quizzes 90% — ready for Hard'],
        ['id' => $dee->id, 'name' => 'Dee', 'yearGroup' => 'Year 10', 'reasons' => 'Geometry quizzes 90% — ready for harder questions'],
    ]);
});

it('shows only the best strand and joins attendance and quiz reasons', function () {
    $geometry = Strand::factory()->create(['name' => 'Geometry']);
    $algebra = Strand::factory()->create(['name' => 'Algebra']);
    $ava = watchedStudent($this->group, 'Ava');
    takeQuizzes($ava, $geometry, 3, 9);
    takeQuizzes($ava, $algebra, 3, 10);
    markStudent($this->group, $ava, AttendanceStatus::Absent, '2');

    expect(watchList($this->tutor)[0]['reasons'])->toBe('Absent last class · Algebra quizzes 100% — ready for Hard');
});

it('lists at most five, attendance concerns first, and only the tutor\'s own students', function () {
    $geometry = Strand::factory()->create(['name' => 'Geometry']);
    foreach (['A', 'B', 'C'] as $name) {
        $student = watchedStudent($this->group, "Quiz {$name}");
        takeQuizzes($student, $geometry, 3, 9);
    }
    foreach (['D', 'E', 'F'] as $name) {
        $student = watchedStudent($this->group, "Late {$name}");
        markStudent($this->group, $student, AttendanceStatus::Late, '12');
        markStudent($this->group, $student, AttendanceStatus::Late, '5');
    }
    $otherGroup = ClassGroup::factory()->create();
    $outsider = watchedStudent($otherGroup, 'Outsider');
    markStudent($otherGroup, $outsider, AttendanceStatus::Absent, '3');
    $inactive = ClassGroup::factory()->create(['tutor_id' => $this->tutor->id, 'is_active' => false]);
    $dormant = watchedStudent($inactive, 'Dormant');
    takeQuizzes($dormant, $geometry, 3, 10);

    $names = collect(watchList($this->tutor))->pluck('name')->all();

    expect($names)->toBe(['Late D', 'Late E', 'Late F', 'Quiz A', 'Quiz B']);
});

it('does not round a quiz average just under 85% up into the list', function () {
    $geometry = Strand::factory()->create(['name' => 'Geometry']);
    $eve = watchedStudent($this->group, 'Eve');
    $quiz = Quiz::factory()->create(['strand_id' => $geometry->id]);

    foreach ([85, 85, 85, 84, 84] as $score) {
        QuizAttempt::factory()->submitted($score, 100)->create(['user_id' => $eve->id, 'quiz_id' => $quiz->id]);
    }

    expect(watchList($this->tutor))->toBe([]);
});
