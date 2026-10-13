<?php

use App\Enums\AttendanceStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:30:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create(['name' => 'Tunde Tutor']);
    $this->group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 5 Maths']);
    $this->ava = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava']);
    $this->bola = User::factory()->student(YearGroup::Year5)->create(['name' => 'Bola']);
    $this->group->enrol($this->ava);
    $this->group->enrol($this->bola);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

it('shows the register with the class, tutor and students', function () {
    $this->actingAs($this->tutor)->get(route('lessons.register.show', $this->lesson))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lessons/register')
            ->where('lesson.className', 'Year 5 Maths')
            ->where('lesson.date', 'Saturday 4 October')
            ->where('lesson.time', '10:00–11:00')
            ->where('tutor.name', 'Tunde Tutor')
            ->has('students', 2)
            ->where('students.0.name', 'Ava')
            ->where('students.0.status', null)
            ->where('canEditTutor', false)
            ->has('statuses', 4));
});

it('saves the register, working out lateness from arrival times', function () {
    $this->actingAs($this->tutor)->put(route('lessons.register.update', $this->lesson), [
        'attendance' => [
            $this->ava->id => ['status' => 'present'],
            $this->bola->id => ['status' => 'late', 'arrived_at' => '10:08', 'notes' => 'Bus was late'],
        ],
    ])->assertRedirect(route('lessons.register.show', $this->lesson))
        ->assertInertiaFlash('toast.type', 'success');

    $records = $this->lesson->attendanceRecords()->get()->keyBy('user_id');
    expect($records[$this->ava->id]->status)->toBe(AttendanceStatus::Present)
        ->and($records[$this->bola->id]->status)->toBe(AttendanceStatus::Late)
        ->and($records[$this->bola->id]->minutes_late)->toBe(8)
        ->and($records[$this->bola->id]->notes)->toBe('Bus was late')
        ->and(LatenessReport::count())->toBe(1);

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('students.1.status', 'late')
            ->where('students.1.arrivedAt', '10:08')
            ->where('students.1.minutesLate', 8));
});

it('treats a "late" arrival time inside the grace period as on time', function () {
    $this->actingAs($this->tutor)->put(route('lessons.register.update', $this->lesson), [
        'attendance' => [$this->ava->id => ['status' => 'late', 'arrived_at' => '10:00']],
    ]);

    expect($this->lesson->attendanceRecords()->first()->status)->toBe(AttendanceStatus::Present)
        ->and(LatenessReport::count())->toBe(0);
});

it('needs an arrival time for a late arrival and a known status', function () {
    $this->actingAs($this->tutor)->put(route('lessons.register.update', $this->lesson), [
        'attendance' => [
            $this->ava->id => ['status' => 'late'],
            $this->bola->id => ['status' => 'asleep'],
        ],
    ])->assertSessionHasErrors(["attendance.{$this->ava->id}.arrived_at", "attendance.{$this->bola->id}.status"]);

    expect($this->lesson->attendanceRecords()->count())->toBe(0);
});

it('rejects people who are not in this lesson and saves nothing', function (string $who) {
    $outsider = match ($who) {
        'other class' => tap(User::factory()->student()->create(), fn ($student) => ClassGroup::factory()->create()->enrol($student)),
        'guardian' => User::factory()->guardian()->create(),
    };

    $this->actingAs($this->tutor)->put(route('lessons.register.update', $this->lesson), [
        'attendance' => [
            $this->ava->id => ['status' => 'present'],
            $outsider->id => ['status' => 'present'],
        ],
    ])->assertSessionHasErrors('attendance');

    expect($this->lesson->attendanceRecords()->count())->toBe(0);
})->with(['other class', 'guardian']);

it('does not let a tutor edit their own record through the register', function () {
    $this->actingAs($this->tutor)->put(route('lessons.register.update', $this->lesson), [
        'attendance' => [$this->tutor->id => ['status' => 'present']],
    ])->assertSessionHasErrors('attendance');
});

it('lets an admin correct anyone, including the tutor, at any time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-11-20 12:00:00', 'UTC'));
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('lessons.register.show', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('canEditTutor', true));

    $this->actingAs($admin)->put(route('lessons.register.update', $this->lesson), [
        'attendance' => [
            $this->tutor->id => ['status' => 'late', 'arrived_at' => '10:15'],
            $this->ava->id => ['status' => 'excused'],
        ],
    ])->assertSessionHasNoErrors();

    $tutorRecord = $this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first();
    expect($tutorRecord->status)->toBe(AttendanceStatus::Late)
        ->and($tutorRecord->recorded_by_id)->toBe($admin->id)
        ->and($tutorRecord->latenessReport->minutes_late)->toBe(15);
});

it('keeps registers private to the lesson\'s tutor and admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('lessons.register.show', $this->lesson))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists a class\'s recent and upcoming lessons for admins', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.classes.edit', $this->group))
        ->assertInertia(fn (Assert $page) => $page
            ->has('lessons', 1)
            ->where('lessons.0.id', $this->lesson->id)
            ->where('lessons.0.when', 'Sat 4 Oct, 10:00')
            ->where('lessons.0.recorded', 0));
});
