<?php

use App\Enums\Weekday;
use App\Models\ClassSlot;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Runs the real slot migration against old-shape rows. It uses its own in-memory connection, outside
 * RefreshDatabase's transaction, so SQLite's table rebuilds behave as they do under `php artisan migrate`.
 */
beforeEach(function () {
    config(['database.connections.legacy' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    $this->previousConnection = DB::getDefaultConnection();
    DB::setDefaultConnection('legacy');

    $this->artisan('migrate', ['--database' => 'legacy'])->assertSuccessful();

    $this->migration = require collect(glob(database_path('migrations/*_move_class_schedules_into_class_slots.php')))->sole();
    $this->migration->down();
});

afterEach(function () {
    DB::setDefaultConnection($this->previousConnection);
    DB::purge('legacy');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function insertOldClassGroup(array $attributes): int
{
    return DB::table('class_groups')->insertGetId([
        'name' => 'Year 5',
        'tutor_id' => null,
        'year_group' => 5,
        'location' => null,
        'weekday' => null,
        'start_time' => null,
        'duration_minutes' => 60,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

function insertOldLesson(int $classGroupId, int $tutorId, string $startsAt): int
{
    return DB::table('lessons')->insertGetId([
        'class_group_id' => $classGroupId,
        'tutor_id' => $tutorId,
        'starts_at' => $startsAt,
        'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + 5400),
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('starts from the old class shape', function () {
    expect(Schema::hasColumns('class_groups', ['tutor_id', 'weekday', 'start_time', 'duration_minutes', 'location']))->toBeTrue()
        ->and(Schema::hasColumn('lessons', 'class_slot_id'))->toBeFalse();
});

it('turns a scheduled class into one Maths slot with the same tutor, time, length and room', function () {
    $tutor = User::factory()->tutor()->create();
    $classId = insertOldClassGroup([
        'tutor_id' => $tutor->id,
        'weekday' => Weekday::Saturday->value,
        'start_time' => '09:30:00',
        'duration_minutes' => 90,
        'location' => 'Room 2',
    ]);
    $lessonIds = [
        insertOldLesson($classId, $tutor->id, '2025-09-27 08:30:00'),
        insertOldLesson($classId, $tutor->id, '2025-10-04 08:30:00'),
    ];

    $this->migration->up();

    $slot = ClassSlot::query()->where('class_group_id', $classId)->sole();
    expect($slot->subject->name)->toBe('Maths')
        ->and($slot->tutor_id)->toBe($tutor->id)
        ->and($slot->weekday)->toBe(Weekday::Saturday)
        ->and($slot->start_time)->toBe('09:30')
        ->and($slot->duration_minutes)->toBe(90)
        ->and($slot->location)->toBe('Room 2')
        ->and(Lesson::query()->whereKey($lessonIds)->pluck('class_slot_id')->unique()->all())->toBe([$slot->id])
        ->and(Schema::hasColumns('class_groups', ['tutor_id', 'weekday', 'start_time', 'duration_minutes', 'location']))->toBeFalse();
});

it('keeps an unscheduled class as a slot with no day or time', function () {
    $tutor = User::factory()->tutor()->create();
    $classId = insertOldClassGroup(['tutor_id' => $tutor->id]);

    $this->migration->up();

    $slot = ClassSlot::query()->where('class_group_id', $classId)->sole();
    expect($slot->weekday)->toBeNull()
        ->and($slot->start_time)->toBeNull()
        ->and($slot->tutor_id)->toBe($tutor->id)
        ->and($slot->hasSchedule())->toBeFalse();
});

it('keeps a tutorless class as a slot with no tutor', function () {
    $classId = insertOldClassGroup(['weekday' => Weekday::Monday->value, 'start_time' => '16:00:00']);

    $this->migration->up();

    $slot = ClassSlot::query()->where('class_group_id', $classId)->sole();
    expect($slot->tutor_id)->toBeNull()
        ->and($slot->weekday)->toBe(Weekday::Monday)
        ->and($slot->start_time)->toBe('16:00');
});

it('leaves attendance and reports untouched', function () {
    $tutor = User::factory()->tutor()->create();
    $student = User::factory()->student()->create();
    $classId = insertOldClassGroup(['tutor_id' => $tutor->id, 'weekday' => Weekday::Saturday->value, 'start_time' => '09:30:00']);
    $lessonId = insertOldLesson($classId, $tutor->id, '2025-09-27 08:30:00');
    DB::table('class_group_student')->insert(['class_group_id' => $classId, 'student_id' => $student->id, 'enrolled_at' => '2025-09-01']);
    DB::table('attendance_records')->insert([
        ['lesson_id' => $lessonId, 'user_id' => $tutor->id, 'attendee_role' => 'tutor', 'status' => 'present', 'created_at' => now(), 'updated_at' => now()],
        ['lesson_id' => $lessonId, 'user_id' => $student->id, 'attendee_role' => 'student', 'status' => 'late', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('lesson_reports')->insert([
        'lesson_id' => $lessonId, 'author_id' => $tutor->id, 'author_role' => 'tutor', 'rating' => 4,
        'went_well' => 'Fractions', 'submitted_at' => '2025-09-27 10:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = [
        DB::table('attendance_records')->orderBy('id')->get()->toArray(),
        DB::table('lesson_reports')->orderBy('id')->get()->toArray(),
        DB::table('class_group_student')->get()->toArray(),
    ];

    $this->migration->up();

    expect([
        DB::table('attendance_records')->orderBy('id')->get()->toArray(),
        DB::table('lesson_reports')->orderBy('id')->get()->toArray(),
        DB::table('class_group_student')->get()->toArray(),
    ])->toEqual($before)
        ->and(Lesson::query()->find($lessonId)->tutor_id)->toBe($tutor->id);
});

it('restores the class columns from the first slot when rolled back', function () {
    $tutor = User::factory()->tutor()->create();
    $classId = insertOldClassGroup(['tutor_id' => $tutor->id, 'weekday' => Weekday::Friday->value, 'start_time' => '17:00:00', 'duration_minutes' => 75, 'location' => 'Hall']);
    $this->migration->up();
    ClassSlot::factory()->create(['class_group_id' => $classId, 'subject_id' => Subject::factory(), 'weekday' => Weekday::Monday]);

    $this->migration->down();

    $class = DB::table('class_groups')->find($classId);
    expect($class->tutor_id)->toBe($tutor->id)
        ->and($class->weekday)->toBe(Weekday::Friday->value)
        ->and(substr($class->start_time, 0, 5))->toBe('17:00')
        ->and($class->duration_minutes)->toBe(75)
        ->and($class->location)->toBe('Hall')
        ->and(Schema::hasColumn('lessons', 'class_slot_id'))->toBeFalse();
});
