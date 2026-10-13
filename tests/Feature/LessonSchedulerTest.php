<?php

use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\Weekday;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Services\LessonScheduler;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lesson_horizon_days' => 28]);
    // Wednesday 1 October 2025, 12:00 in Lagos (11:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2025-10-01 11:00:00', 'UTC'));
});

it('creates four weekly lessons in the academy timezone, stored in UTC', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '10:00', 'duration_minutes' => 90]);

    $created = app(LessonScheduler::class)->generate();

    expect($created)->toBe(4);
    $starts = $group->lessons()->orderBy('starts_at')->pluck('starts_at')->map->toDateTimeString()->all();
    expect($starts)->toBe([
        '2025-10-04 09:00:00',
        '2025-10-11 09:00:00',
        '2025-10-18 09:00:00',
        '2025-10-25 09:00:00',
    ]);

    $first = $group->lessons()->orderBy('starts_at')->first();
    expect($first->ends_at->toDateTimeString())->toBe('2025-10-04 10:30:00')
        ->and($first->tutor_id)->toBe($group->tutor_id)
        ->and($first->status)->toBe(LessonStatus::Scheduled);
});

it('keeps an early-morning class on the right date', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '00:30']);

    app(LessonScheduler::class)->generateFor($group);

    $first = $group->lessons()->orderBy('starts_at')->first();
    expect($first->starts_at->toDateTimeString())->toBe('2025-10-03 23:30:00')
        ->and($first->starts_at->setTimezone('Africa/Lagos')->isSaturday())->toBeTrue();
});

it('never creates lessons that would already have started', function () {
    // Today is Wednesday; a Wednesday 09:00 class has already started today.
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Wednesday, 'start_time' => '09:00']);

    app(LessonScheduler::class)->generateFor($group);

    expect($group->lessons()->orderBy('starts_at')->first()->starts_at->toDateString())->toBe('2025-10-08');
});

it('is safe to run twice', function () {
    ClassGroup::factory()->create(['weekday' => Weekday::Saturday]);

    app(LessonScheduler::class)->generate();
    $second = app(LessonScheduler::class)->generate();

    expect($second)->toBe(0)->and(Lesson::count())->toBe(4);
});

it('skips inactive classes, classes without a schedule and classes without a tutor', function () {
    ClassGroup::factory()->create(['is_active' => false]);
    ClassGroup::factory()->create(['weekday' => null, 'start_time' => null]);
    ClassGroup::factory()->create(['tutor_id' => null]);

    expect(app(LessonScheduler::class)->generate())->toBe(0);
});

it('moves future lessons when the schedule changes but leaves started ones alone', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '10:00']);
    $past = Lesson::factory()->for($group)->create([
        'starts_at' => CarbonImmutable::parse('2025-09-27 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-09-27 10:00:00', 'UTC'),
        'status' => LessonStatus::Completed,
    ]);
    app(LessonScheduler::class)->generateFor($group);

    $group->update(['weekday' => Weekday::Sunday, 'start_time' => '14:00']);
    app(LessonScheduler::class)->rescheduleFuture($group);

    $future = $group->lessons()->where('starts_at', '>', now())->orderBy('starts_at')->get();
    expect($future)->toHaveCount(4)
        ->and($future->first()->starts_at->toDateTimeString())->toBe('2025-10-05 13:00:00')
        ->and($past->fresh())->not->toBeNull();
});

it('clears future lessons when a class is deactivated', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday]);
    app(LessonScheduler::class)->generateFor($group);

    $group->update(['is_active' => false]);
    app(LessonScheduler::class)->rescheduleFuture($group);

    expect($group->lessons()->count())->toBe(0);
});

it('runs from the console', function () {
    ClassGroup::factory()->create(['weekday' => Weekday::Saturday]);

    $this->artisan('lessons:generate', ['--days' => 14])
        ->expectsOutputToContain('Created 2 lessons')
        ->assertSuccessful();
});

it('skips classes whose tutor has been deactivated', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday]);
    $group->tutor->delete();

    expect(app(LessonScheduler::class)->generate())->toBe(0)
        ->and(app(LessonScheduler::class)->generateFor($group->fresh()))->toBe(0);
});

it('schedules from the stored class, not a stale copy', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '10:00']);
    ClassGroup::whereKey($group->id)->update(['weekday' => Weekday::Sunday->value, 'start_time' => '14:00:00']);

    app(LessonScheduler::class)->generateFor($group);

    expect($group->lessons()->orderBy('starts_at')->first()->starts_at->toDateTimeString())->toBe('2025-10-05 13:00:00');
});

it('never deletes a future lesson that already has attendance', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '10:00']);
    app(LessonScheduler::class)->generateFor($group);
    $lesson = $group->lessons()->orderBy('starts_at')->first();
    AttendanceRecord::factory()->create(['lesson_id' => $lesson->id, 'user_id' => $group->tutor_id, 'attendee_role' => AttendeeRole::Tutor]);

    $group->update(['weekday' => Weekday::Sunday]);
    app(LessonScheduler::class)->rescheduleFuture($group);

    expect($lesson->fresh())->not->toBeNull();
});
