# Phase 3a: Classes, Enrolments & Lesson Scheduling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins set up recurring classes (tutor, weekday, start time, duration) and enrol students. The system turns each class into dated lessons for the coming four weeks, ready for the registers in Phase 3b.

**Architecture:**

- **Classes:** a recurring class is a `ClassGroup` (`class` is reserved in PHP). Each dated occurrence is a `Lesson`.
- **Enrolments:** these live on a `class_group_student` pivot with `enrolled_at`/`left_at`. Withdrawing a student sets `left_at`, so history is kept.
- **Scheduling:** a `LessonScheduler` service turns each class's weekly schedule into lessons. It interprets the schedule in the academy timezone (`config('academy.timezone')`, default `Africa/Lagos`), stores times in UTC, and is idempotent (unique `class_group_id + starts_at`). It runs:
    - daily, through the `lessons:generate` command;
    - when a class is created;
    - when a class's schedule changes, which replaces its future lessons.
- **Display:** the server formats times in the academy timezone, so the client needs no timezone logic.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Wayfinder, Tailwind v4, Pest 5.

**Spec:** [docs/architecture/database-schema.md](../../architecture/database-schema.md) §4 (`class_groups`, `class_group_student`, `lessons`) · [docs/plans/implementation-plan.md](../../plans/implementation-plan.md) Phase 3 · [docs/design/design-brief.md](../../design/design-brief.md) §5 Attendance

## Global Constraints

- **Decisions D4 and D5 are approved (2026-10-13):** tutors mark students, tutors check themselves in, and admins can override; the lateness grace period is 0 minutes and configurable. Both are consumed in 3b; 3a only adds the config key.
- Store every datetime in UTC (`app.timezone` stays `UTC`). Interpret and display class schedules in `config('academy.timezone')`.
- Weekdays use ISO numbering: 1 = Monday … 7 = Sunday (`Weekday` int-backed enum).
- Only create lessons whose start is **in the future**. Never delete or move lessons that have already started.
- Only users with `Role::Tutor` who aren't deactivated can be a class's tutor. Only active `Role::Student` users can be enrolled.
- Account invariants from 2b still apply (memory `phase-2b-constraints`). This phase creates no users.
- Admin pages sit under `/admin` behind `auth`, `verified`, `role:admin`. Use Wayfinder imports, design-system components, UK English, and `FieldError` with `aria-describedby` on every input.
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `npm run check:fix && npm run types:check` for TS/TSX changes. Tests run with `php artisan test --compact <path>`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Timezone conversion**: a Saturday 10:00 class in `Africa/Lagos` (UTC+1) must produce lessons at 09:00 UTC on Saturdays, not at 10:00 UTC and not on the wrong date near midnight. → Task 2 test.
2. **Running generation twice** creates no duplicate lessons. → Task 2 test.
3. **Changing a class's schedule**: future lessons move to the new slot, while lessons that already started stay exactly as they were. → Task 2 and Task 3 tests.
4. **Re-enrolling a withdrawn student** reuses the enrolment row (`left_at` cleared), with no duplicate-key error. → Task 1 and Task 4 tests.
5. **Enrolling or assigning the wrong kind of user** (a guardian as a student, a student as tutor, a deactivated tutor) is rejected with a field error. → Task 3 and Task 4 tests.

---

## File Structure

| File                                                                                                         | Responsibility                                                             |
| ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| `config/academy.php`                                                                                         | `timezone`, `lateness_grace_minutes`, `lesson_horizon_days`                |
| `app/Enums/Weekday.php`                                                                                      | ISO weekdays plus labels and options                                       |
| `app/Enums/LessonStatus.php`                                                                                 | Scheduled / InProgress / Completed / Cancelled                             |
| `database/migrations/*_create_class_groups_table.php`                                                        | `class_groups` + `class_group_student`                                     |
| `database/migrations/*_create_lessons_table.php`                                                             | `lessons`                                                                  |
| `app/Models/ClassGroup.php`                                                                                  | relationships, `enrol()`, `withdraw()`, `hasSchedule()`, `scheduleLabel()` |
| `app/Models/Lesson.php`                                                                                      | relationships, casts                                                       |
| `app/Models/User.php`                                                                                        | `classGroupsTaught()`, `enrolledClassGroups()`, `lessonsTaught()`          |
| `database/factories/{ClassGroupFactory,LessonFactory}.php`                                                   | factories                                                                  |
| `app/Services/LessonScheduler.php`                                                                           | `generate()`, `generateFor()`, `rescheduleFuture()`                        |
| `app/Console/Commands/GenerateLessons.php`                                                                   | `lessons:generate`, scheduled daily                                        |
| `app/Http/Controllers/Admin/ClassGroupController.php`                                                        | index, create, store, edit, update                                         |
| `app/Http/Controllers/Admin/ClassEnrolmentController.php`                                                    | store, destroy                                                             |
| `app/Http/Requests/Admin/{ClassGroupRequest,EnrolStudentRequest}.php`                                        | validation                                                                 |
| `resources/js/pages/admin/classes/{index,create,edit}.tsx`, `resources/js/components/class-group-fields.tsx` | UI                                                                         |

---

### Task 1: Enums, config, schema and models

**Files:**

- Create: `config/academy.php`, `app/Enums/Weekday.php`, `app/Enums/LessonStatus.php`, the migrations, `app/Models/ClassGroup.php`, `app/Models/Lesson.php`, `database/factories/ClassGroupFactory.php`, `database/factories/LessonFactory.php`
- Modify: `app/Models/User.php`
- Test: `tests/Unit/WeekdayTest.php`, `tests/Feature/ClassGroupTest.php`

**Interfaces:**

- Consumes: `User`, `Role`, `YearGroup`, the factory states.
- Produces:
    - **Enums:** `Weekday::Monday…Sunday` (1–7) with `label(): string` (e.g. "Saturday"), `plural(): string` ("Saturdays") and `static options(): array<int, array{value:int,label:string}>`; `LessonStatus::{Scheduled,InProgress,Completed,Cancelled}` with values `'scheduled'|'in_progress'|'completed'|'cancelled'`.
    - **`ClassGroup`:**
        - attributes: `name`, `tutor_id`, `year_group: ?YearGroup`, `location`, `weekday: ?Weekday`, `start_time: ?string` ("HH:MM"), `duration_minutes: int`, `is_active: bool`;
        - relations: `tutor(): BelongsTo`, `students(): BelongsToMany` (pivot `enrolled_at`, `left_at`), `activeStudents(): BelongsToMany`, `lessons(): HasMany`;
        - methods: `enrol(User $student): void`, `withdraw(User $student): void`, `hasSchedule(): bool`, `scheduleLabel(): string`.
    - **`Lesson`:** `class_group_id`, `tutor_id`, `starts_at` and `ends_at` (immutable datetimes, UTC), `status: LessonStatus`, `cancelled_reason`; relations `classGroup()`, `tutor()`.
    - **`User`:** `classGroupsTaught(): HasMany`, `enrolledClassGroups(): BelongsToMany`, `lessonsTaught(): HasMany`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest --unit WeekdayTest --no-interaction`:

```php
<?php

use App\Enums\Weekday;

it('uses ISO weekday numbers with readable labels', function () {
    expect(Weekday::Monday->value)->toBe(1)
        ->and(Weekday::Sunday->value)->toBe(7)
        ->and(Weekday::Saturday->label())->toBe('Saturday')
        ->and(Weekday::Saturday->plural())->toBe('Saturdays')
        ->and(Weekday::options())->toHaveCount(7)
        ->and(Weekday::options()[0])->toBe(['value' => 1, 'label' => 'Monday']);
});
```

`php artisan make:test --pest ClassGroupTest --no-interaction`:

```php
<?php

use App\Enums\LessonStatus;
use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;

it('belongs to a tutor and describes its schedule', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->for($tutor, 'tutor')->create([
        'weekday' => Weekday::Saturday,
        'start_time' => '10:00',
        'duration_minutes' => 90,
    ]);

    expect($group->tutor->is($tutor))->toBeTrue()
        ->and($group->hasSchedule())->toBeTrue()
        ->and($group->scheduleLabel())->toBe('Saturdays at 10:00 · 90 min')
        ->and($tutor->classGroupsTaught()->first()->is($group))->toBeTrue();
});

it('has no schedule label without a weekday and time', function () {
    $group = ClassGroup::factory()->create(['weekday' => null, 'start_time' => null]);

    expect($group->hasSchedule())->toBeFalse()
        ->and($group->scheduleLabel())->toBe('No regular schedule');
});

it('enrols, withdraws and re-enrols a student, keeping one row', function () {
    $group = ClassGroup::factory()->create();
    $student = User::factory()->student()->create();

    $group->enrol($student);
    expect($group->activeStudents()->pluck('users.id')->all())->toBe([$student->id]);

    $group->withdraw($student);
    expect($group->activeStudents()->count())->toBe(0)
        ->and($group->students()->count())->toBe(1);

    $group->enrol($student);
    expect($group->activeStudents()->count())->toBe(1);
    $this->assertDatabaseCount('class_group_student', 1);
    expect($student->enrolledClassGroups()->first()->is($group))->toBeTrue();
});

it('refuses to enrol someone who is not a student', function (string $state) {
    ClassGroup::factory()->create()->enrol(User::factory()->{$state}()->create());
})->with(['guardian', 'tutor', 'admin'])->throws(InvalidArgumentException::class);

it('stores lessons against a class and tutor', function () {
    $lesson = Lesson::factory()->create();

    expect($lesson->status)->toBe(LessonStatus::Scheduled)
        ->and($lesson->classGroup->lessons()->first()->is($lesson))->toBeTrue()
        ->and($lesson->tutor->lessonsTaught()->first()->is($lesson))->toBeTrue()
        ->and($lesson->ends_at->greaterThan($lesson->starts_at))->toBeTrue();
});

it('allows only one lesson per class at a given start time', function () {
    $lesson = Lesson::factory()->create();

    Lesson::factory()->for($lesson->classGroup)->create(['starts_at' => $lesson->starts_at]);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/WeekdayTest.php tests/Feature/ClassGroupTest.php`
Expected: FAIL. `App\Enums\Weekday` and `App\Models\ClassGroup` aren't found.

- [ ] **Step 3: Config and enums**

`config/academy.php`:

```php
<?php

return [

    /*
    | The timezone class schedules are written in and times are shown in.
    | Everything is stored in UTC.
    */
    'timezone' => env('ACADEMY_TIMEZONE', 'Africa/Lagos'),

    /*
    | Minutes after a lesson's start before an arrival counts as late (decision D5).
    */
    'lateness_grace_minutes' => (int) env('ACADEMY_LATENESS_GRACE_MINUTES', 0),

    /*
    | How many days ahead lessons are generated from each class's schedule.
    */
    'lesson_horizon_days' => 28,

];
```

Add `ACADEMY_TIMEZONE=Africa/Lagos` to `.env.example` and `.env`.

Write `app/Enums/Weekday.php` directly (`make:enum` misplaces files once `app/Enums` exists; see the Phase 1 ledger):

```php
<?php

namespace App\Enums;

enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public function label(): string
    {
        return $this->name;
    }

    public function plural(): string
    {
        return $this->name.'s';
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $weekday): array => ['value' => $weekday->value, 'label' => $weekday->label()],
            self::cases(),
        );
    }
}
```

`app/Enums/LessonStatus.php`:

```php
<?php

namespace App\Enums;

enum LessonStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 4: Migrations**

`php artisan make:migration create_class_groups_table --no-interaction`:

```php
public function up(): void
{
    Schema::create('class_groups', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->foreignId('tutor_id')->nullable()->constrained('users')->nullOnDelete();
        $table->unsignedTinyInteger('year_group')->nullable();
        $table->string('location')->nullable();
        $table->unsignedTinyInteger('weekday')->nullable();
        $table->time('start_time')->nullable();
        $table->unsignedSmallInteger('duration_minutes')->default(60);
        $table->boolean('is_active')->default(true)->index();
        $table->timestamps();
    });

    Schema::create('class_group_student', function (Blueprint $table) {
        $table->id();
        $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
        $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
        $table->date('enrolled_at');
        $table->date('left_at')->nullable();
        $table->timestamps();

        $table->unique(['class_group_id', 'student_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('class_group_student');
    Schema::dropIfExists('class_groups');
}
```

`php artisan make:migration create_lessons_table --no-interaction`:

```php
public function up(): void
{
    Schema::create('lessons', function (Blueprint $table) {
        $table->id();
        $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
        $table->foreignId('tutor_id')->constrained('users');
        $table->dateTime('starts_at')->index();
        $table->dateTime('ends_at');
        $table->string('status')->default('scheduled')->index();
        $table->string('cancelled_reason')->nullable();
        $table->timestamps();

        $table->unique(['class_group_id', 'starts_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('lessons');
}
```

- [ ] **Step 5: Models**

`php artisan make:model ClassGroup --factory --no-interaction`, then `app/Models/ClassGroup.php`:

```php
<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A recurring class, e.g. "Year 5 Maths – Saturday". Each dated occurrence is a Lesson.
 *
 * @property int $id
 * @property string $name
 * @property int|null $tutor_id
 * @property YearGroup|null $year_group
 * @property string|null $location
 * @property Weekday|null $weekday
 * @property string|null $start_time
 * @property int $duration_minutes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'tutor_id', 'year_group', 'location', 'weekday', 'start_time', 'duration_minutes', 'is_active'])]
class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_group' => YearGroup::class,
            'weekday' => Weekday::class,
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Times are kept as "HH:MM" whatever the database returns (SQLite/MySQL may add seconds).
     */
    protected function startTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    /**
     * Every student who has ever been enrolled, including those who have left.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_group_student', 'class_group_id', 'student_id')
            ->withPivot(['enrolled_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function activeStudents(): BelongsToMany
    {
        return $this->students()->wherePivotNull('left_at');
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * Enrol a student, or re-enrol one who previously left.
     *
     * @throws InvalidArgumentException
     */
    public function enrol(User $student): void
    {
        if (! $student->hasRole(Role::Student)) {
            throw new InvalidArgumentException('Only students can be enrolled in a class.');
        }

        $pivot = ['enrolled_at' => now()->toDateString(), 'left_at' => null];

        if ($this->students()->whereKey($student->id)->exists()) {
            $this->students()->updateExistingPivot($student->id, $pivot);

            return;
        }

        $this->students()->attach($student->id, $pivot);
    }

    /**
     * Mark a student as having left, keeping their enrolment history.
     */
    public function withdraw(User $student): void
    {
        $this->students()->updateExistingPivot($student->id, ['left_at' => now()->toDateString()]);
    }

    public function hasSchedule(): bool
    {
        return $this->weekday !== null && $this->start_time !== null;
    }

    public function scheduleLabel(): string
    {
        if (! $this->hasSchedule()) {
            return 'No regular schedule';
        }

        return "{$this->weekday->plural()} at {$this->start_time} · {$this->duration_minutes} min";
    }
}
```

`php artisan make:model Lesson --factory --no-interaction`, then `app/Models/Lesson.php`:

```php
<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated occurrence of a ClassGroup. Times are UTC.
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $tutor_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property LessonStatus $status
 * @property string|null $cancelled_reason
 */
#[Fillable(['class_group_id', 'tutor_id', 'starts_at', 'ends_at', 'status', 'cancelled_reason'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => LessonStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }
}
```

In `app/Models/User.php`, add the imports `App\Models\ClassGroup`/`Lesson` (same namespace, so no import is needed) and `Illuminate\Database\Eloquent\Relations\HasMany`, plus:

```php
/**
 * @return HasMany<ClassGroup, $this>
 */
public function classGroupsTaught(): HasMany
{
    return $this->hasMany(ClassGroup::class, 'tutor_id');
}

/**
 * @return BelongsToMany<ClassGroup, $this>
 */
public function enrolledClassGroups(): BelongsToMany
{
    return $this->belongsToMany(ClassGroup::class, 'class_group_student', 'student_id', 'class_group_id')
        ->withPivot(['enrolled_at', 'left_at'])
        ->withTimestamps();
}

/**
 * @return HasMany<Lesson, $this>
 */
public function lessonsTaught(): HasMany
{
    return $this->hasMany(Lesson::class, 'tutor_id');
}
```

- [ ] **Step 6: Factories**

`database/factories/ClassGroupFactory.php` `definition()`:

```php
return [
    'name' => 'Year '.fake()->numberBetween(3, 9).' Maths – '.fake()->dayOfWeek(),
    'tutor_id' => User::factory()->tutor(),
    'year_group' => null,
    'location' => fake()->randomElement(['Room 1', 'Room 2', 'Online']),
    'weekday' => fake()->randomElement(Weekday::cases()),
    'start_time' => '10:00',
    'duration_minutes' => 60,
    'is_active' => true,
];
```

(imports `App\Enums\Weekday`, `App\Models\User`)

`database/factories/LessonFactory.php` `definition()`:

```php
$startsAt = CarbonImmutable::now()->addDay()->setTime(9, 0);

return [
    'class_group_id' => ClassGroup::factory(),
    'tutor_id' => fn (array $attributes) => ClassGroup::find($attributes['class_group_id'])->tutor_id,
    'starts_at' => $startsAt,
    'ends_at' => $startsAt->addHour(),
    'status' => LessonStatus::Scheduled,
];
```

(imports `App\Enums\LessonStatus`, `App\Models\ClassGroup`, `Carbon\CarbonImmutable`)

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Unit/WeekdayTest.php tests/Feature/ClassGroupTest.php
```

Expected: PASS (1 + 1 + 1 + 1 + 3 + 1 + 1 = 9).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add -A && git commit -m "feat: class groups, enrolments and lessons schema"
```

---

### Task 2: Lesson scheduler and daily command

**Files:**

- Create: `app/Services/LessonScheduler.php`, `app/Console/Commands/GenerateLessons.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/LessonSchedulerTest.php`

**Interfaces:**

- Consumes: `ClassGroup`, `Lesson`, `LessonStatus`, `config('academy.*')`.
- Produces:
    - `LessonScheduler::generate(?int $days = null): int` (all active, scheduled classes that have a tutor; returns the number created);
    - `LessonScheduler::generateFor(ClassGroup $group, ?int $days = null): int`;
    - `LessonScheduler::rescheduleFuture(ClassGroup $group): void` (deletes `Scheduled` lessons that haven't started, then regenerates if the class is active);
    - the command `lessons:generate {--days=}`, scheduled daily at 01:00 academy time.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest LessonSchedulerTest --no-interaction`:

```php
<?php

use App\Enums\LessonStatus;
use App\Enums\Weekday;
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/LessonSchedulerTest.php`
Expected: FAIL with `Class "App\Services\LessonScheduler" not found`.

- [ ] **Step 3: The scheduler**

`php artisan make:class Services/LessonScheduler --no-interaction` (check the location afterwards), then:

```php
<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use Carbon\CarbonImmutable;

/**
 * Turns each class's weekly schedule into dated lessons. The schedule is read in
 * the academy timezone; lessons are stored in UTC.
 */
class LessonScheduler
{
    /**
     * Generate upcoming lessons for every active class that has a schedule and a tutor.
     */
    public function generate(?int $days = null): int
    {
        return ClassGroup::query()
            ->where('is_active', true)
            ->whereNotNull('weekday')
            ->whereNotNull('start_time')
            ->whereNotNull('tutor_id')
            ->get()
            ->sum(fn (ClassGroup $group): int => $this->generateFor($group, $days));
    }

    /**
     * Generate the class's upcoming lessons that don't exist yet. Returns how many were created.
     */
    public function generateFor(ClassGroup $group, ?int $days = null): int
    {
        if (! $group->is_active || ! $group->hasSchedule() || $group->tutor_id === null) {
            return 0;
        }

        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($timezone)->startOfDay();
        $days ??= (int) config('academy.lesson_horizon_days');
        [$hour, $minute] = array_map('intval', explode(':', $group->start_time));
        $created = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $today->addDays($offset);

            if ($date->dayOfWeekIso !== $group->weekday->value) {
                continue;
            }

            $startsAt = $date->setTime($hour, $minute)->utc();

            if ($startsAt->lessThanOrEqualTo($now)) {
                continue;
            }

            $lesson = Lesson::query()->firstOrCreate(
                ['class_group_id' => $group->id, 'starts_at' => $startsAt],
                [
                    'tutor_id' => $group->tutor_id,
                    'ends_at' => $startsAt->addMinutes($group->duration_minutes),
                    'status' => LessonStatus::Scheduled,
                ],
            );

            $created += $lesson->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * Replace the class's not-yet-started scheduled lessons after its schedule, tutor or status changed.
     */
    public function rescheduleFuture(ClassGroup $group): void
    {
        $group->lessons()
            ->where('status', LessonStatus::Scheduled)
            ->where('starts_at', '>', CarbonImmutable::now())
            ->delete();

        $this->generateFor($group);
    }
}
```

`firstOrCreate` keyed on `starts_at` works because the cast serialises a `CarbonImmutable` to the stored `Y-m-d H:i:s` UTC string. If SQLite comparison misses because of the format, set `'starts_at' => $startsAt->toDateTimeString()` in the lookup and ledger it. Phase 3b will add "and has no attendance records" to the delete in `rescheduleFuture`.

- [ ] **Step 4: Command and schedule**

`php artisan make:command GenerateLessons --no-interaction`, then `app/Console/Commands/GenerateLessons.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\LessonScheduler;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

class GenerateLessons extends Command
{
    /**
     * @var string
     */
    protected $signature = 'lessons:generate {--days= : How many days ahead to generate}';

    /**
     * @var string
     */
    protected $description = "Create upcoming lessons from each class's weekly schedule";

    public function handle(LessonScheduler $scheduler): int
    {
        $days = $this->option('days') === null ? null : (int) $this->option('days');

        $created = $scheduler->generate($days);

        $this->info("Created {$created} lessons.");

        return self::SUCCESS;
    }
}
```

Match whatever structure `make:command` generates in Laravel 13 (it may use `#[AsCommand]` or the properties shown). Keep the signature and output text exactly as above, and remove the `AsCommand` import if it's unused.

`routes/console.php`, add:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('lessons:generate')
    ->dailyAt('01:00')
    ->timezone(config('academy.timezone'))
    ->withoutOverlapping();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/LessonSchedulerTest.php`
Expected: PASS (8 tests). Also run `php artisan schedule:list`, and expect `lessons:generate` daily at 01:00 (shown in UTC as 00:00).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: generate lessons from class schedules"
```

---

### Task 3: Admin class management

**Files:**

- Create: `app/Http/Controllers/Admin/ClassGroupController.php`, `app/Http/Requests/Admin/ClassGroupRequest.php`, `resources/js/components/class-group-fields.tsx`, `resources/js/pages/admin/classes/{index,create,edit}.tsx`
- Modify: `routes/web.php`, `resources/js/layouts/app-layout.tsx`, `resources/js/pages/dashboard/admin.tsx`
- Test: `tests/Feature/Admin/ClassGroupManagementTest.php`

**Interfaces:**

- Consumes: `ClassGroup`, `LessonScheduler`, `Weekday::options()`, `YearGroup::options()`, `Select`, `Toast`, `FieldError`.
- Produces:
    - routes `admin.classes.index|create|store|edit|update` (resource `classes`, parameter `{classGroup}`);
    - page props: `index` `{ classes: Row[] }`; `create` and `edit` share `{ tutors: Option[], weekdays: Option[], yearGroups: Option[] }`, and `edit` adds `classGroup`;
    - `ClassGroupFields({ errors, tutors, weekdays, yearGroups, defaults? })`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Admin/ClassGroupManagementTest --no-interaction`:

```php
<?php

use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-01 11:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->tutor = User::factory()->tutor()->create(['name' => 'Tunde Tutor']);
});

function classPayload(array $overrides = []): array
{
    return [
        'name' => 'Year 5 Maths – Saturday',
        'tutor_id' => test()->tutor->id,
        'year_group' => 5,
        'location' => 'Room 1',
        'weekday' => 6,
        'start_time' => '10:00',
        'duration_minutes' => 60,
        'is_active' => true,
        ...$overrides,
    ];
}

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('admin.classes.index'))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists classes with tutor, schedule, size and next lesson', function () {
    $group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 5 Maths', 'weekday' => Weekday::Saturday, 'start_time' => '10:00']);
    $group->enrol(User::factory()->student()->create());

    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['name' => 'Year 6 Maths']));

    $this->actingAs($this->admin)->get(route('admin.classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/classes/index')
            ->has('classes', 2)
            ->where('classes.0.name', 'Year 5 Maths')
            ->where('classes.0.tutor', 'Tunde Tutor')
            ->where('classes.0.schedule', 'Saturdays at 10:00 · 60 min')
            ->where('classes.0.students', 1)
            ->where('classes.1.nextLesson', 'Sat 4 Oct, 10:00'));
});

it('creates a class and schedules its lessons', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload())
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');
    expect($group->weekday)->toBe(Weekday::Saturday)
        ->and($group->lessons()->count())->toBe(4);
});

it('rejects a tutor who is not a tutor or has been deactivated', function () {
    $deactivated = User::factory()->tutor()->create();
    $deactivated->delete();

    foreach ([User::factory()->student()->create()->id, $deactivated->id] as $tutorId) {
        $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['tutor_id' => $tutorId]))
            ->assertSessionHasErrors('tutor_id');
    }
});

it('needs a weekday and a start time together', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['start_time' => '']))
        ->assertSessionHasErrors('start_time');

    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['weekday' => '']))
        ->assertSessionHasErrors('weekday');
});

it('validates times, weekdays and durations', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'bad time' => [['start_time' => '25:00'], 'start_time'],
    'bad weekday' => [['weekday' => 8], 'weekday'],
    'too short' => [['duration_minutes' => 5], 'duration_minutes'],
    'too long' => [['duration_minutes' => 600], 'duration_minutes'],
]);

it('shows the edit page with options', function () {
    $group = ClassGroup::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/classes/edit')
            ->where('classGroup.id', $group->id)
            ->has('weekdays', 7)
            ->has('tutors'));
});

it('reschedules future lessons when the schedule changes', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload());
    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload(['weekday' => 7, 'start_time' => '14:00']))
        ->assertRedirect(route('admin.classes.edit', $group));

    expect($group->lessons()->orderBy('starts_at')->first()->starts_at->toDateTimeString())->toBe('2025-10-05 13:00:00')
        ->and($group->lessons()->count())->toBe(4);
});

it('leaves lessons alone when only the name changes', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload());
    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');
    $ids = $group->lessons()->pluck('id')->all();

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload(['name' => 'Renamed']));

    expect($group->lessons()->pluck('id')->all())->toBe($ids);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/ClassGroupManagementTest.php`
Expected: FAIL with `Route [admin.classes.index] not defined`.

- [ ] **Step 3: Routes**

In the admin group in `routes/web.php` (add `use App\Http\Controllers\Admin\ClassGroupController;`):

```php
Route::resource('classes', ClassGroupController::class)
    ->except(['show', 'destroy'])
    ->parameters(['classes' => 'classGroup']);
```

- [ ] **Step 4: Form request**

`php artisan make:request Admin/ClassGroupRequest --no-interaction`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassGroupRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'weekday' => $this->filled('weekday') ? $this->input('weekday') : null,
            'start_time' => $this->filled('start_time') ? $this->input('start_time') : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tutor_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Tutor->value)->whereNull('deleted_at'),
            ],
            'year_group' => ['nullable', Rule::enum(YearGroup::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'weekday' => ['nullable', 'required_with:start_time', Rule::enum(Weekday::class)],
            'start_time' => ['nullable', 'required_with:weekday', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tutor_id.exists' => 'Choose an active tutor.',
            'weekday.required_with' => 'Choose a day for the start time.',
            'start_time.required_with' => 'Choose a start time for the day.',
        ];
    }
}
```

- [ ] **Step 5: Controller**

`php artisan make:controller Admin/ClassGroupController --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClassGroupRequest;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LessonScheduler;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClassGroupController extends Controller
{
    public function index(): Response
    {
        $classes = ClassGroup::query()
            ->with(['tutor', 'lessons' => fn ($query) => $query->where('starts_at', '>', now())->orderBy('starts_at')->limit(1)])
            ->withCount('activeStudents')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'tutor' => $group->tutor?->name,
                'schedule' => $group->scheduleLabel(),
                'students' => $group->active_students_count,
                'nextLesson' => $group->lessons->first()?->starts_at->setTimezone(config('academy.timezone'))->format('D j M, H:i'),
                'isActive' => $group->is_active,
            ]);

        return Inertia::render('admin/classes/index', ['classes' => $classes]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/classes/create', $this->formOptions());
    }

    public function store(ClassGroupRequest $request, LessonScheduler $scheduler): RedirectResponse
    {
        $group = ClassGroup::create($request->validated());
        $scheduler->generateFor($group);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$group->name} has been created."]);

        return to_route('admin.classes.edit', $group);
    }

    public function edit(ClassGroup $classGroup): Response
    {
        return Inertia::render('admin/classes/edit', [
            ...$this->formOptions(),
            'classGroup' => [
                'id' => $classGroup->id,
                'name' => $classGroup->name,
                'tutorId' => $classGroup->tutor_id,
                'yearGroup' => $classGroup->year_group?->value,
                'location' => $classGroup->location,
                'weekday' => $classGroup->weekday?->value,
                'startTime' => $classGroup->start_time,
                'durationMinutes' => $classGroup->duration_minutes,
                'isActive' => $classGroup->is_active,
                'schedule' => $classGroup->scheduleLabel(),
            ],
        ]);
    }

    public function update(ClassGroupRequest $request, ClassGroup $classGroup, LessonScheduler $scheduler): RedirectResponse
    {
        $classGroup->fill($request->validated());
        $scheduleChanged = $classGroup->isDirty(['weekday', 'start_time', 'duration_minutes', 'tutor_id', 'is_active']);
        $classGroup->save();

        if ($scheduleChanged) {
            $scheduler->rescheduleFuture($classGroup);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$classGroup->name} has been saved."]);

        return to_route('admin.classes.edit', $classGroup);
    }

    /**
     * @return array{tutors: array<int, array{value: int, label: string}>, weekdays: array<int, array{value: int, label: string}>, yearGroups: array<int, array{value: int, label: string}>}
     */
    private function formOptions(): array
    {
        return [
            'tutors' => User::query()
                ->where('role', Role::Tutor)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $tutor): array => ['value' => $tutor->id, 'label' => $tutor->name])
                ->all(),
            'weekdays' => Weekday::options(),
            'yearGroups' => YearGroup::options(),
        ];
    }
}
```

**Note on `isDirty('start_time')`:** the getter trims to "HH:MM", but the dirty check compares raw attributes. A stored `"10:00:00"` compared against an incoming `"10:00"` would look dirty. `leaves lessons alone when only the name changes` catches this. If it fails, add a `set:` to the `startTime` Attribute that normalises to `"HH:MM:00"`, or compare `substr($classGroup->getOriginal('start_time'), 0, 5)` against the new value. Ledger the choice. The unused `Lesson` import gets removed by Pint, or drop it yourself.

- [ ] **Step 6: Pages, fields and nav**

`resources/js/components/class-group-fields.tsx`:

```tsx
import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

export type ClassGroupDefaults = {
    name?: string;
    tutorId?: number | null;
    yearGroup?: number | null;
    location?: string | null;
    weekday?: number | null;
    startTime?: string | null;
    durationMinutes?: number;
    isActive?: boolean;
};

type ClassGroupFieldsProps = {
    errors: Record<string, string | undefined>;
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
    defaults?: ClassGroupDefaults;
};

export default function ClassGroupFields({
    errors,
    tutors,
    weekdays,
    yearGroups,
    defaults = {},
}: ClassGroupFieldsProps) {
    return (
        <>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="name">Class name</Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={defaults.name}
                    placeholder="e.g. Year 5 Maths – Saturday"
                    required
                    aria-invalid={!!errors.name}
                    aria-describedby="name-error"
                />
                <FieldError id="name-error" message={errors.name} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="tutor_id">Tutor</Label>
                    <Select
                        id="tutor_id"
                        name="tutor_id"
                        defaultValue={defaults.tutorId ?? ''}
                        aria-invalid={!!errors.tutor_id}
                        aria-describedby="tutor_id-error"
                    >
                        <option value="">No tutor yet</option>
                        {tutors.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <FieldError id="tutor_id-error" message={errors.tutor_id} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="year_group">Year group (optional)</Label>
                    <Select
                        id="year_group"
                        name="year_group"
                        defaultValue={defaults.yearGroup ?? ''}
                        aria-invalid={!!errors.year_group}
                        aria-describedby="year_group-error"
                    >
                        <option value="">Mixed / not set</option>
                        {yearGroups.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <FieldError
                        id="year_group-error"
                        message={errors.year_group}
                    />
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="weekday">Day</Label>
                    <Select
                        id="weekday"
                        name="weekday"
                        defaultValue={defaults.weekday ?? ''}
                        aria-invalid={!!errors.weekday}
                        aria-describedby="weekday-error"
                    >
                        <option value="">No regular day</option>
                        {weekdays.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <FieldError id="weekday-error" message={errors.weekday} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="start_time">Start time</Label>
                    <Input
                        id="start_time"
                        name="start_time"
                        type="time"
                        defaultValue={defaults.startTime ?? ''}
                        aria-invalid={!!errors.start_time}
                        aria-describedby="start_time-error"
                    />
                    <FieldError
                        id="start_time-error"
                        message={errors.start_time}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="duration_minutes">Length (minutes)</Label>
                    <Input
                        id="duration_minutes"
                        name="duration_minutes"
                        type="number"
                        min={15}
                        max={240}
                        step={5}
                        defaultValue={defaults.durationMinutes ?? 60}
                        required
                        aria-invalid={!!errors.duration_minutes}
                        aria-describedby="duration_minutes-error"
                    />
                    <FieldError
                        id="duration_minutes-error"
                        message={errors.duration_minutes}
                    />
                </div>
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="location">Room or link (optional)</Label>
                <Input
                    id="location"
                    name="location"
                    defaultValue={defaults.location ?? ''}
                    aria-invalid={!!errors.location}
                    aria-describedby="location-error"
                />
                <FieldError id="location-error" message={errors.location} />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0" />
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    defaultChecked={defaults.isActive ?? true}
                    className="size-4 accent-primary"
                />
                Active (lessons are scheduled)
            </label>
        </>
    );
}
```

`resources/js/pages/admin/classes/index.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { create, edit } from '@/routes/admin/classes';

type Row = {
    id: number;
    name: string;
    tutor: string | null;
    schedule: string;
    students: number;
    nextLesson: string | null;
    isActive: boolean;
};

export default function ClassesIndex({ classes }: { classes: Row[] }) {
    return (
        <AppLayout title="Classes">
            <div>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                >
                    Add class
                </Link>
            </div>
            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-surface-muted text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Class
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Tutor
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Schedule
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Students
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Next lesson
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {classes.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No classes yet. Add your first class to
                                        start scheduling lessons.
                                    </td>
                                </tr>
                            )}
                            {classes.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-3 font-medium">
                                        {row.name}
                                        {!row.isActive && (
                                            <Badge
                                                tone="excused"
                                                className="ml-2"
                                            >
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.tutor ?? (
                                            <span className="text-muted-foreground">
                                                No tutor
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {row.schedule}
                                    </td>
                                    <td className="px-4 py-3 tabular-nums">
                                        {row.students}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {row.nextLesson ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={edit(row.id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            Edit
                                            <span className="sr-only">
                                                {' '}
                                                {row.name}
                                            </span>
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
```

`resources/js/pages/admin/classes/create.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import ClassGroupFields from '@/components/class-group-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/admin/classes';
import type { Option } from '@/types';

type CreateClassProps = {
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
};

export default function CreateClass({
    tutors,
    weekdays,
    yearGroups,
}: CreateClassProps) {
    return (
        <AppLayout title="Add class">
            <Card className="max-w-2xl">
                <CardContent>
                    <Form {...store.form()} className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <ClassGroupFields
                                    errors={errors}
                                    tutors={tutors}
                                    weekdays={weekdays}
                                    yearGroups={yearGroups}
                                />
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? 'Creating…' : 'Create class'}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
```

`resources/js/pages/admin/classes/edit.tsx`, the details card only (Task 4 adds the enrolment card):

```tsx
import { Form } from '@inertiajs/react';
import ClassGroupFields from '@/components/class-group-fields';
import type { ClassGroupDefaults } from '@/components/class-group-fields';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/admin/classes';
import type { Option } from '@/types';

export type EditableClassGroup = ClassGroupDefaults & {
    id: number;
    name: string;
    schedule: string;
};

type EditClassProps = {
    classGroup: EditableClassGroup;
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
};

export default function EditClass({
    classGroup,
    tutors,
    weekdays,
    yearGroups,
}: EditClassProps) {
    return (
        <AppLayout title={classGroup.name}>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                        <CardDescription>
                            {classGroup.schedule}. Changing the day, time,
                            length or tutor updates upcoming lessons.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form(classGroup.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <ClassGroupFields
                                        errors={errors}
                                        tutors={tutors}
                                        weekdays={weekdays}
                                        yearGroups={yearGroups}
                                        defaults={classGroup}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        Save class
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
```

Nav, in `app-layout.tsx` `navItems.admin`, add `{ label: 'Classes', href: adminClassesIndex }` with `import { index as adminClassesIndex } from '@/routes/admin/classes';`. In `dashboard/admin.tsx`, add a second card, "Classes": description `"Set up recurring classes, assign tutors and enrol students. Lessons are scheduled four weeks ahead."` with a "Manage classes" link to `adminClassesIndex()`. Wrap both cards in `<div className="grid gap-4 md:grid-cols-2">`.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Admin/ClassGroupManagementTest.php
```

Expected: PASS (3 + 1 + 1 + 1 + 1 + 4 + 1 + 1 + 1 = 14).

- [ ] **Step 8: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
php artisan test --compact
git add -A && git commit -m "feat: admin class management"
```

---

### Task 4: Enrol and withdraw students

**Files:**

- Create: `app/Http/Controllers/Admin/ClassEnrolmentController.php`, `app/Http/Requests/Admin/EnrolStudentRequest.php`
- Modify: `routes/web.php`, `app/Http/Controllers/Admin/ClassGroupController.php` (`edit` props), `resources/js/pages/admin/classes/edit.tsx`, `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Admin/ClassEnrolmentTest.php`, `tests/Feature/DatabaseSeederTest.php`

**Interfaces:**

- Consumes: `ClassGroup::enrol()` / `withdraw()` (Task 1) and the class edit page (Task 3).
- Produces:
    - routes `admin.classes.students.store` (`POST /admin/classes/{classGroup}/students`, body `student_id`) and `admin.classes.students.destroy` (`DELETE /admin/classes/{classGroup}/students/{student}`);
    - edit props `students: { id, name, username, yearGroup, enrolledOn }[]` and `candidates: Option[]`;
    - a seeded demo class "Year 5 Maths – Saturday", taught by the demo tutor, with `ava.year5` enrolled and lessons generated.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Admin/ClassEnrolmentTest --no-interaction`:

```php
<?php

use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->group = ClassGroup::factory()->create();
});

it('enrols a student', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertRedirect(route('admin.classes.edit', $this->group))
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->group->activeStudents()->pluck('users.id')->all())->toBe([$student->id]);
});

it('only enrols active students', function () {
    $deactivated = User::factory()->student()->create();
    $deactivated->delete();

    foreach ([User::factory()->guardian()->create()->id, $deactivated->id, 999999] as $id) {
        $this->actingAs($this->admin)
            ->post(route('admin.classes.students.store', $this->group), ['student_id' => $id])
            ->assertSessionHasErrors('student_id');
    }

    expect($this->group->students()->count())->toBe(0);
});

it('rejects a student who is already enrolled', function () {
    $student = User::factory()->student()->create();
    $this->group->enrol($student);

    $this->actingAs($this->admin)
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertSessionHasErrors('student_id');
});

it('withdraws a student but keeps the history, and can re-enrol them', function () {
    $student = User::factory()->student()->create();
    $this->group->enrol($student);

    $this->actingAs($this->admin)
        ->delete(route('admin.classes.students.destroy', [$this->group, $student]))
        ->assertRedirect(route('admin.classes.edit', $this->group));

    expect($this->group->activeStudents()->count())->toBe(0)
        ->and($this->group->students()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertSessionHasNoErrors();

    expect($this->group->activeStudents()->count())->toBe(1);
});

it('404s when withdrawing someone who is not in the class', function () {
    $this->actingAs($this->admin)
        ->delete(route('admin.classes.students.destroy', [$this->group, User::factory()->student()->create()]))
        ->assertNotFound();
});

it('shows enrolled students and the students who can still be added', function () {
    $enrolled = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava']);
    $this->group->enrol($enrolled);
    User::factory()->student(YearGroup::Year6)->create(['name' => 'Bola']);
    User::factory()->guardian()->create(['name' => 'Not A Student']);

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $this->group))
        ->assertInertia(fn (Assert $page) => $page
            ->has('students', 1)
            ->where('students.0.name', 'Ava')
            ->where('students.0.yearGroup', 'Year 5')
            ->has('candidates', 1)
            ->where('candidates.0.label', 'Bola (Year 6)'));
});

it('keeps enrolment admin-only', function () {
    $student = User::factory()->student()->create();

    $this->actingAs(User::factory()->tutor()->create())
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertForbidden();
});
```

Append to `tests/Feature/DatabaseSeederTest.php`:

```php
it('seeds a demo class with an enrolled student and upcoming lessons', function () {
    $this->seed();

    $group = \App\Models\ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');

    expect($group->tutor->email)->toBe('tutor@academy.test')
        ->and($group->activeStudents()->pluck('username')->all())->toBe(['ava.year5'])
        ->and($group->lessons()->count())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/ClassEnrolmentTest.php tests/Feature/DatabaseSeederTest.php`
Expected: FAIL with `Route [admin.classes.students.store] not defined`, plus the seeder assertion.

- [ ] **Step 3: Routes**

In the admin group (add `use App\Http\Controllers\Admin\ClassEnrolmentController;`):

```php
Route::post('classes/{classGroup}/students', [ClassEnrolmentController::class, 'store'])->name('classes.students.store');
Route::delete('classes/{classGroup}/students/{student}', [ClassEnrolmentController::class, 'destroy'])->name('classes.students.destroy');
```

- [ ] **Step 4: Request and controller**

`php artisan make:request Admin/EnrolStudentRequest --no-interaction`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\ClassGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EnrolStudentRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Student->value)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('student_id')) {
                    return;
                }

                /** @var ClassGroup $group */
                $group = $this->route('classGroup');

                if ($group->activeStudents()->whereKey($this->integer('student_id'))->exists()) {
                    $validator->errors()->add('student_id', 'That student is already in this class.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['student_id.exists' => 'Choose an active student.'];
    }
}
```

`php artisan make:controller Admin/ClassEnrolmentController --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnrolStudentRequest;
use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ClassEnrolmentController extends Controller
{
    public function store(EnrolStudentRequest $request, ClassGroup $classGroup): RedirectResponse
    {
        $student = User::findOrFail($request->integer('student_id'));
        $classGroup->enrol($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$student->name} has joined {$classGroup->name}."]);

        return to_route('admin.classes.edit', $classGroup);
    }

    public function destroy(ClassGroup $classGroup, User $student): RedirectResponse
    {
        abort_unless($classGroup->activeStudents()->whereKey($student->id)->exists(), 404);

        $classGroup->withdraw($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$student->name} has left {$classGroup->name}."]);

        return to_route('admin.classes.edit', $classGroup);
    }
}
```

- [ ] **Step 5: Edit-page props and UI**

In `ClassGroupController::edit()`, add to the props:

```php
'students' => $classGroup->activeStudents()
    ->with('studentProfile')
    ->orderBy('name')
    ->get()
    ->map(fn (User $student): array => [
        'id' => $student->id,
        'name' => $student->name,
        'username' => $student->username,
        'yearGroup' => $student->studentProfile?->year_group->label(),
        'enrolledOn' => \Illuminate\Support\Carbon::parse($student->pivot->enrolled_at)->format('j M Y'),
    ]),
'candidates' => User::query()
    ->where('role', Role::Student)
    ->whereDoesntHave('enrolledClassGroups', fn ($query) => $query
        ->whereKey($classGroup->id)
        ->whereNull('class_group_student.left_at'))
    ->with('studentProfile')
    ->orderBy('name')
    ->get()
    ->map(fn (User $student): array => [
        'value' => $student->id,
        'label' => $student->name.($student->studentProfile ? " ({$student->studentProfile->year_group->label()})" : ''),
    ]),
```

Import `Illuminate\Support\Carbon` at the top rather than using the FQCN inline. `whereKey($classGroup->id)` inside the relation constraint targets `class_groups.id`. If the generated SQL is ambiguous, use `->where('class_groups.id', $classGroup->id)` and ledger it.

In `resources/js/pages/admin/classes/edit.tsx`:

- extend the props with `students: Student[]` and `candidates: Option[]`, where `type Student = { id: number; name: string; username: string | null; yearGroup: string | null; enrolledOn: string };`;
- import `Link`, `FieldError`, `Select`, `Label`, `{ destroy as withdrawStudent, store as enrolStudent } from '@/routes/admin/classes/students'`;
- add a second card to the grid:

```tsx
<Card>
    <CardHeader>
        <CardTitle>Students ({students.length})</CardTitle>
    </CardHeader>
    <CardContent className="flex flex-col gap-4">
        <Form
            {...enrolStudent.form(classGroup.id)}
            resetOnSuccess
            className="flex items-end gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="flex flex-1 flex-col gap-1.5">
                        <Label htmlFor="student_id">Add a student</Label>
                        <Select
                            id="student_id"
                            name="student_id"
                            defaultValue=""
                            required
                            aria-invalid={!!errors.student_id}
                            aria-describedby="student_id-error"
                        >
                            <option value="" disabled>
                                Choose a student
                            </option>
                            {candidates.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError
                            id="student_id-error"
                            message={errors.student_id}
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing || candidates.length === 0}
                    >
                        Add
                    </Button>
                </>
            )}
        </Form>
        {students.length === 0 ? (
            <p className="text-sm text-muted-foreground">
                No students in this class yet.
            </p>
        ) : (
            <ul className="divide-y">
                {students.map((student) => (
                    <li
                        key={student.id}
                        className="flex items-center justify-between gap-3 py-2"
                    >
                        <div>
                            <p className="font-medium">{student.name}</p>
                            <p className="text-xs text-muted-foreground">
                                {student.username}
                                {student.yearGroup
                                    ? ` · ${student.yearGroup}`
                                    : ''}{' '}
                                · joined {student.enrolledOn}
                            </p>
                        </div>
                        <Link
                            href={withdrawStudent([classGroup.id, student.id])}
                            as="button"
                            onBefore={() =>
                                confirm(
                                    `Remove ${student.name} from this class?`,
                                )
                            }
                            className="text-sm font-medium text-danger underline-offset-4 hover:underline"
                        >
                            Remove
                            <span className="sr-only"> {student.name}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        )}
    </CardContent>
</Card>
```

If Wayfinder's `destroy` needs an object for two params, use `withdrawStudent({ classGroup: classGroup.id, student: student.id })` and ledger it.

- [ ] **Step 6: Seed a demo class**

In `database/seeders/DatabaseSeeder.php` (add `use App\Enums\Weekday;`, `use App\Models\ClassGroup;` and `use App\Services\LessonScheduler;`), keep a handle on the tutor and Ava:

```php
$tutor = User::factory()->tutor()->create([...existing attributes...]);
...
$ava = User::factory()->student(YearGroup::Year5)->create([...existing attributes...]);
$parent->linkChild($ava, 'Parent');
```

then append:

```php
$class = ClassGroup::create([
    'name' => 'Year 5 Maths – Saturday',
    'tutor_id' => $tutor->id,
    'year_group' => YearGroup::Year5,
    'location' => 'Room 1',
    'weekday' => Weekday::Saturday,
    'start_time' => '10:00',
    'duration_minutes' => 60,
    'is_active' => true,
]);
$class->enrol($ava);
app(LessonScheduler::class)->generateFor($class);
```

Restructure the existing inline `linkChild(User::factory()…)` calls only as much as needed to keep the `$ava` handle. The seeder test's username and email expectations must still hold.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Admin/ClassEnrolmentTest.php tests/Feature/DatabaseSeederTest.php
```

Expected: PASS (7 + 4 = 11).

- [ ] **Step 8: Full verification and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check && npm run build
php artisan test --compact
php artisan migrate:fresh --seed --no-interaction
git add -A && git commit -m "feat: enrol and withdraw students from classes"
```

Expected: everything is green.

Smoke test as `admin@academy.test`: open Classes, where "Year 5 Maths – Saturday" shows its next lesson. Open it, and Ava is listed. Add Leo, remove him, and add him again.
