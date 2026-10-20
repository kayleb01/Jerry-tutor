<?php

use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\Weekday;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Services\LessonScheduler;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lesson_horizon_days' => 28]);
    // Wednesday 1 October 2025, 12:00 in Lagos (11:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2025-10-01 11:00:00', 'UTC'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function scheduledSlot(array $attributes = []): ClassSlot
{
    return ClassSlot::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '10:00', ...$attributes]);
}

it('creates four weekly lessons in the academy timezone, stored in UTC', function () {
    $slot = scheduledSlot(['duration_minutes' => 90]);

    $created = app(LessonScheduler::class)->generate();

    expect($created)->toBe(4);
    $starts = $slot->lessons()->orderBy('starts_at')->pluck('starts_at')->map->toDateTimeString()->all();
    expect($starts)->toBe([
        '2025-10-04 09:00:00',
        '2025-10-11 09:00:00',
        '2025-10-18 09:00:00',
        '2025-10-25 09:00:00',
    ]);

    $first = $slot->lessons()->orderBy('starts_at')->first();
    expect($first->ends_at->toDateTimeString())->toBe('2025-10-04 10:30:00')
        ->and($first->tutor_id)->toBe($slot->tutor_id)
        ->and($first->class_group_id)->toBe($slot->class_group_id)
        ->and($first->status)->toBe(LessonStatus::Scheduled);
});

it('generates lessons for each slot of a class with that slot\'s tutor and times', function () {
    $tutorA = User::factory()->tutor()->create();
    $tutorB = User::factory()->tutor()->create();
    $year5 = ClassGroup::factory()->create(['name' => 'Year 5']);
    $maths = ClassSlot::factory()->for($year5)->for($tutorA, 'tutor')->create(['weekday' => Weekday::Monday, 'start_time' => '09:00', 'duration_minutes' => 120]);
    $science = ClassSlot::factory()->for($year5)->for($tutorB, 'tutor')->create([
        'subject_id' => Subject::factory()->create(['name' => 'Science'])->id,
        'weekday' => Weekday::Monday,
        'start_time' => '12:00',
        'duration_minutes' => 120,
    ]);

    app(LessonScheduler::class)->generate(7);

    $lessons = $year5->lessons()->orderBy('starts_at')->get();
    expect($lessons)->toHaveCount(2)
        ->and($lessons->map(fn (Lesson $lesson): array => [
            $lesson->class_slot_id,
            $lesson->tutor_id,
            $lesson->localStart()->format('D H:i'),
            $lesson->localEnd()->format('H:i'),
        ])->all())->toBe([
            [$maths->id, $tutorA->id, 'Mon 09:00', '11:00'],
            [$science->id, $tutorB->id, 'Mon 12:00', '14:00'],
        ]);
});

it('keeps an early-morning slot on the right date', function () {
    $slot = scheduledSlot(['start_time' => '00:30']);

    app(LessonScheduler::class)->generateFor($slot);

    $first = $slot->lessons()->orderBy('starts_at')->first();
    expect($first->starts_at->toDateTimeString())->toBe('2025-10-03 23:30:00')
        ->and($first->starts_at->setTimezone('Africa/Lagos')->isSaturday())->toBeTrue();
});

it('never creates lessons that would already have started', function () {
    // Today is Wednesday; a Wednesday 09:00 slot has already started today.
    $slot = scheduledSlot(['weekday' => Weekday::Wednesday, 'start_time' => '09:00']);

    app(LessonScheduler::class)->generateFor($slot);

    expect($slot->lessons()->orderBy('starts_at')->first()->starts_at->toDateString())->toBe('2025-10-08');
});

it('is safe to run twice', function () {
    scheduledSlot();

    app(LessonScheduler::class)->generate();
    $second = app(LessonScheduler::class)->generate();

    expect($second)->toBe(0)->and(Lesson::count())->toBe(4);
});

it('skips slots without a tutor or a time, and inactive classes', function () {
    scheduledSlot(['class_group_id' => ClassGroup::factory()->create(['is_active' => false])->id]);
    scheduledSlot(['weekday' => null, 'start_time' => null]);
    scheduledSlot(['tutor_id' => null]);
    scheduledSlot()->delete();

    expect(app(LessonScheduler::class)->generate())->toBe(0)
        ->and(Lesson::count())->toBe(0);
});

it('skips slots whose tutor has been deactivated', function () {
    $slot = scheduledSlot();
    $slot->tutor->delete();

    expect(app(LessonScheduler::class)->generate())->toBe(0)
        ->and(app(LessonScheduler::class)->generateFor($slot->fresh()))->toBe(0);
});

it('reschedules only the edited slot and keeps attended lessons', function () {
    $group = ClassGroup::factory()->create();
    $edited = scheduledSlot(['class_group_id' => $group->id]);
    $other = scheduledSlot(['class_group_id' => $group->id, 'start_time' => '14:00']);
    $past = Lesson::factory()->for($group)->create([
        'class_slot_id' => $edited->id,
        'starts_at' => CarbonImmutable::parse('2025-09-27 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-09-27 10:00:00', 'UTC'),
        'status' => LessonStatus::Completed,
    ]);
    app(LessonScheduler::class)->generate();
    $attended = $edited->lessons()->where('starts_at', '>', now())->orderBy('starts_at')->first();
    AttendanceRecord::factory()->create(['lesson_id' => $attended->id, 'user_id' => $edited->tutor_id, 'attendee_role' => AttendeeRole::Tutor]);
    $otherLessonIds = $other->lessons()->orderBy('id')->pluck('id')->all();

    $edited->update(['weekday' => Weekday::Sunday, 'start_time' => '15:00']);
    app(LessonScheduler::class)->rescheduleFuture($edited);

    $future = $edited->lessons()->where('starts_at', '>', now())->whereKeyNot($attended->id)->orderBy('starts_at')->get();
    expect($future)->toHaveCount(4)
        ->and($future->first()->starts_at->toDateTimeString())->toBe('2025-10-05 14:00:00')
        ->and($past->fresh())->not->toBeNull()
        ->and($attended->fresh())->not->toBeNull()
        ->and($other->lessons()->orderBy('id')->pluck('id')->all())->toBe($otherLessonIds);
});

it('moves a slot\'s future lessons to its new tutor', function () {
    $slot = scheduledSlot();
    $before = $slot->tutor_id;
    app(LessonScheduler::class)->generateFor($slot);
    $newTutor = User::factory()->tutor()->create();

    $slot->update(['tutor_id' => $newTutor->id]);
    app(LessonScheduler::class)->rescheduleFuture($slot);

    expect($slot->lessons()->pluck('tutor_id')->unique()->all())->toBe([$newTutor->id])
        ->and($newTutor->id)->not->toBe($before);
});

it('removing a slot deletes its future unattended lessons and keeps the past', function () {
    $slot = scheduledSlot();
    $past = Lesson::factory()->for($slot->classGroup)->create([
        'class_slot_id' => $slot->id,
        'starts_at' => CarbonImmutable::parse('2025-09-27 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-09-27 10:00:00', 'UTC'),
        'status' => LessonStatus::Completed,
    ]);
    app(LessonScheduler::class)->generateFor($slot);
    $attended = $slot->lessons()->where('starts_at', '>', now())->orderBy('starts_at')->first();
    AttendanceRecord::factory()->create(['lesson_id' => $attended->id, 'user_id' => $slot->tutor_id, 'attendee_role' => AttendeeRole::Tutor]);

    $slot->delete();
    app(LessonScheduler::class)->removeFuture($slot);

    expect($slot->lessons()->pluck('id')->sort()->values()->all())->toBe([$past->id, $attended->id])
        ->and($past->fresh()->slot->is($slot))->toBeTrue()
        ->and(app(LessonScheduler::class)->generate())->toBe(0);
});

it('clears future lessons when a class is deactivated', function () {
    $slot = scheduledSlot();
    app(LessonScheduler::class)->generateFor($slot);

    $slot->classGroup->update(['is_active' => false]);
    app(LessonScheduler::class)->rescheduleFuture($slot);

    expect($slot->lessons()->count())->toBe(0);
});

it('runs from the console', function () {
    scheduledSlot();

    $this->artisan('lessons:generate', ['--days' => 14])
        ->expectsOutputToContain('Created 2 lessons')
        ->assertSuccessful();
});

it('schedules from the stored slot, not a stale copy', function () {
    $slot = scheduledSlot();
    ClassSlot::whereKey($slot->id)->update(['weekday' => Weekday::Sunday->value, 'start_time' => '14:00:00']);

    app(LessonScheduler::class)->generateFor($slot);

    expect($slot->lessons()->orderBy('starts_at')->first()->starts_at->toDateTimeString())->toBe('2025-10-05 13:00:00');
});

it('labels a slot\'s weekly time', function () {
    expect(scheduledSlot(['weekday' => Weekday::Monday, 'start_time' => '09:00', 'duration_minutes' => 120])->timeLabel())->toBe('Mon 09:00–11:00')
        ->and(scheduledSlot(['weekday' => null, 'start_time' => null])->timeLabel())->toBe('No regular time');
});
