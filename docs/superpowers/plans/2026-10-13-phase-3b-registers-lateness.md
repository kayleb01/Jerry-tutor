# Phase 3b: Check-in, Registers & Lateness Reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:**

- Tutors check in to their lessons and take the register.
- Lateness is worked out from real times, and every late arrival, student or tutor, gets a lateness report automatically.
- Admins can correct any record.
- Anyone still unmarked shortly after a lesson ends is recorded as absent.

**Architecture:**

- **One record per person per lesson:** there is one `attendance_records` row for each person at each lesson, and the tutor gets one too.
- **Writes go through one action:** every write goes through the `RecordAttendance` action. Callers say whether the person attended (`Present` plus an arrival time), was `Absent` or was `Excused`. The action calculates `Late` from the lesson start and `config('academy.lateness_grace_minutes')`. In the same transaction it creates, updates or deletes the matching `lateness_reports` row (snapshot fields).
- **Permissions:** a `LessonPolicy` decides who can check in, take a register, and record a tutor's attendance.
- **Clean-up and display:** a `attendance:mark-no-shows` command closes lessons 15 minutes after they end. Times are shown in the academy timezone, all formatted on the server.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Wayfinder, Tailwind v4, Pest 5.

**Builds on:** 3a, including its review fixes (`LessonScheduler` re-reads and locks, ignores deactivated tutors; deactivating a tutor nulls their classes' `tutor_id`).

**Spec:** [docs/architecture/database-schema.md](../../architecture/database-schema.md) §4 (`attendance_records`, `lateness_reports`) · [docs/design/design-brief.md](../../design/design-brief.md) §4–5 · project PDF §2.1–2.2 · [docs/plans/implementation-plan.md](../../plans/implementation-plan.md) Phase 3 · builds on [Phase 3a](2026-10-13-phase-3a-classes-scheduling.md)

## Global Constraints

- **D4:** tutors mark their students, tutors check _themselves_ in, and admins can override anything, including a tutor's own record. **D5:** grace period `config('academy.lateness_grace_minutes')`, default 0. Someone is late when **whole minutes after the start > grace**, so with grace 0, arriving 1:00 after the start is late and arriving 0:59 after isn't.
- **Students and tutors follow identical rules,** and both produce lateness reports (brief principle 1, "fair and transparent").
- **A lateness report** snapshots who was late, their role, the class name, the lesson date (academy timezone), the scheduled start, the arrival time and the minutes late. When a record stops being late, its report is deleted. When the arrival time changes, the report is updated.
- `RecordAttendance` is the **only** code that writes `attendance_records` or `lateness_reports`.
- **The tutor's window:** a tutor can check in and take a register from 30 minutes before the start until 24 hours after the end. Admins can act at any time. Nobody can record against a cancelled lesson.
- **Who can be on a register:** only the lesson's tutor and students who are (or were) enrolled in its class. Anyone else is rejected.
- **Time handling:** store times in UTC. Arrival times entered as `HH:MM` are read on the lesson's local date in `config('academy.timezone')`.
- **Writes to the 3a code:** `LessonScheduler::rescheduleFuture()` must never delete a lesson that has attendance records.
- Account invariants still hold (memory `phase-2b-constraints`). Use Wayfinder imports, design-system components, UK English and neutral staff copy ("Arrived 7 min after start"), and give every input `FieldError` and `aria-describedby`.
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `npm run check:fix && npm run types:check` for TS/TSX changes. Tests run with `php artisan test --compact <path>`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **The lateness boundary**: arriving exactly at the start, 59 seconds after, 1 minute after, and with a grace period of 5 minutes (4 minutes late counts as on time, 6 minutes as late). → Task 2 tests.
2. **Corrections**: marking someone late, then correcting them to on time, removes the report; changing their arrival time updates the report's minutes; marking them absent removes it. → Task 2 tests.
3. **Another tutor's lesson, or outside the window**: a tutor taking another tutor's register, or their own a week early, gets a 403. A guardian or student always gets a 403. → Task 3 and Task 4 tests.
4. **Someone who isn't part of the lesson** can't be added to a register: a student from another class, a guardian, or a random id gives a validation error. → Task 4 test.
5. **No-shows**: the command marks only people with no record, never overwrites a record, skips cancelled lessons, and is safe to run twice. → Task 5 test.

---

## File Structure

| File                                                                                                                            | Responsibility                                                                               |
| ------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `app/Enums/AttendeeRole.php`, `app/Enums/AttendanceStatus.php`                                                                  | enums                                                                                        |
| `database/migrations/*_create_attendance_tables.php`                                                                            | `attendance_records` + `lateness_reports`                                                    |
| `app/Models/AttendanceRecord.php`, `app/Models/LatenessReport.php`                                                              | models                                                                                       |
| `app/Models/Lesson.php`                                                                                                         | `attendanceRecords()`, `latenessReports()`, `localStart()`, `localEnd()`, `localTimeToUtc()` |
| `database/factories/AttendanceRecordFactory.php`                                                                                | factory                                                                                      |
| `app/Actions/RecordAttendance.php`                                                                                              | the only writer of attendance and lateness                                                   |
| `app/Policies/LessonPolicy.php`                                                                                                 | `checkIn`, `takeRegister`, `recordTutorAttendance`                                           |
| `app/Http/Controllers/DashboardController.php`                                                                                  | tutor "Today" data                                                                           |
| `app/Http/Controllers/TutorCheckInController.php`                                                                               | `POST /lessons/{lesson}/check-in`                                                            |
| `app/Http/Controllers/RegisterController.php`                                                                                   | `GET`/`PUT /lessons/{lesson}/register`                                                       |
| `app/Http/Requests/UpdateRegisterRequest.php`                                                                                   | register validation                                                                          |
| `app/Console/Commands/MarkNoShows.php`                                                                                          | `attendance:mark-no-shows`                                                                   |
| `app/Services/LessonScheduler.php`                                                                                              | the no-delete-with-attendance guard                                                          |
| `resources/js/pages/dashboard/tutor.tsx`, `resources/js/pages/lessons/register.tsx`, `resources/js/components/register-row.tsx` | UI                                                                                           |
| `resources/js/pages/admin/classes/edit.tsx`                                                                                     | lessons list with register links                                                             |

---

### Task 1: Attendance schema and models

**Files:**

- Create: `app/Enums/AttendeeRole.php`, `app/Enums/AttendanceStatus.php`, `database/migrations/<ts>_create_attendance_tables.php`, `app/Models/AttendanceRecord.php`, `app/Models/LatenessReport.php`, `database/factories/AttendanceRecordFactory.php`
- Modify: `app/Models/Lesson.php`
- Test: `tests/Feature/AttendanceModelTest.php`

**Interfaces:**

- Consumes: `Lesson`, `ClassGroup`, `User` (3a).
- Produces:
    - **Enums:** `AttendeeRole::{Student,Tutor}` (`'student'|'tutor'`); `AttendanceStatus::{Present,Late,Absent,Excused}` (`'present'|'late'|'absent'|'excused'`), with `label()` returning "On time", "Late", "Absent" or "Excused".
    - **`AttendanceRecord`:** `lesson_id`, `user_id`, `attendee_role`, `status`, `arrived_at` (immutable, UTC, nullable), `minutes_late` (int, nullable), `recorded_by_id` (nullable, where null means the system recorded it), `notes`; relations `lesson()`, `user()`, `recordedBy()`, `latenessReport()`.
    - **`LatenessReport`:** the snapshot columns; relations `attendanceRecord()`, `user()`, `lesson()`, `reviewedBy()`.
    - **`Lesson`:**
        - `attendanceRecords(): HasMany`, `latenessReports(): HasMany`;
        - `localStart(): CarbonImmutable`, `localEnd(): CarbonImmutable`;
        - `localTimeToUtc(string $time): CarbonImmutable`, which reads `"HH:MM"` on the lesson's local date;
        - `isCancelled(): bool`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest AttendanceModelTest --no-interaction`:

```php
<?php

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\LatenessReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(fn () => config(['academy.timezone' => 'Africa/Lagos']));

it('labels attendance statuses for people', function () {
    expect(AttendanceStatus::Present->label())->toBe('On time')
        ->and(AttendanceStatus::Late->label())->toBe('Late')
        ->and(AttendeeRole::Tutor->value)->toBe('tutor');
});

it('converts lesson times to and from the academy timezone', function () {
    $lesson = Lesson::factory()->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);

    expect($lesson->localStart()->format('Y-m-d H:i'))->toBe('2025-10-04 10:00')
        ->and($lesson->localEnd()->format('H:i'))->toBe('11:00')
        ->and($lesson->localTimeToUtc('10:07')->toDateTimeString())->toBe('2025-10-04 09:07:00')
        ->and($lesson->isCancelled())->toBeFalse();

    $lesson->status = LessonStatus::Cancelled;
    expect($lesson->isCancelled())->toBeTrue();
});

it('links records and reports to their lesson and person', function () {
    $record = AttendanceRecord::factory()->create(['status' => AttendanceStatus::Late, 'minutes_late' => 5]);
    $report = LatenessReport::create([
        'attendance_record_id' => $record->id,
        'user_id' => $record->user_id,
        'attendee_role' => AttendeeRole::Student,
        'lesson_id' => $record->lesson_id,
        'class_name' => 'Year 5 Maths',
        'lesson_date' => '2025-10-04',
        'scheduled_start_at' => $record->lesson->starts_at,
        'arrived_at' => $record->lesson->starts_at->addMinutes(5),
        'minutes_late' => 5,
    ]);

    expect($record->latenessReport->is($report))->toBeTrue()
        ->and($record->lesson->attendanceRecords()->first()->is($record))->toBeTrue()
        ->and($record->lesson->latenessReports()->first()->is($report))->toBeTrue()
        ->and($report->user->is($record->user))->toBeTrue()
        ->and($report->lesson_date->toDateString())->toBe('2025-10-04');
});

it('keeps one record per person per lesson', function () {
    $record = AttendanceRecord::factory()->create();

    AttendanceRecord::factory()->create(['lesson_id' => $record->lesson_id, 'user_id' => $record->user_id]);
})->throws(UniqueConstraintViolationException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/AttendanceModelTest.php`
Expected: FAIL with `Class "App\Enums\AttendanceStatus" not found`.

- [ ] **Step 3: Enums** (write them directly into `app/Enums`)

`app/Enums/AttendeeRole.php`:

```php
<?php

namespace App\Enums;

enum AttendeeRole: string
{
    case Student = 'student';
    case Tutor = 'tutor';
}
```

`app/Enums/AttendanceStatus.php`:

```php
<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excused = 'excused';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'On time',
            self::Late => 'Late',
            self::Absent => 'Absent',
            self::Excused => 'Excused',
        };
    }
}
```

- [ ] **Step 4: Migration**

`php artisan make:migration create_attendance_tables --no-interaction`:

```php
public function up(): void
{
    Schema::create('attendance_records', function (Blueprint $table) {
        $table->id();
        $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained();
        $table->string('attendee_role');
        $table->string('status');
        $table->dateTime('arrived_at')->nullable();
        $table->unsignedSmallInteger('minutes_late')->nullable();
        $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('notes')->nullable();
        $table->timestamps();

        $table->unique(['lesson_id', 'user_id']);
        $table->index(['user_id', 'status']);
    });

    Schema::create('lateness_reports', function (Blueprint $table) {
        $table->id();
        $table->foreignId('attendance_record_id')->unique()->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->index()->constrained();
        $table->string('attendee_role');
        $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
        $table->string('class_name');
        $table->date('lesson_date')->index();
        $table->dateTime('scheduled_start_at');
        $table->dateTime('arrived_at');
        $table->unsignedSmallInteger('minutes_late');
        $table->text('reason')->nullable();
        $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
        $table->dateTime('reviewed_at')->nullable();
        $table->text('follow_up_notes')->nullable();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('lateness_reports');
    Schema::dropIfExists('attendance_records');
}
```

- [ ] **Step 5: Models and factory**

`php artisan make:model AttendanceRecord --factory --no-interaction` and `php artisan make:model LatenessReport --no-interaction`.

`app/Models/AttendanceRecord.php`:

```php
<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Whether one person (student or tutor) attended one lesson, and when they arrived.
 * Only App\Actions\RecordAttendance writes these.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $user_id
 * @property AttendeeRole $attendee_role
 * @property AttendanceStatus $status
 * @property CarbonImmutable|null $arrived_at
 * @property int|null $minutes_late
 * @property int|null $recorded_by_id
 * @property string|null $notes
 */
#[Fillable(['lesson_id', 'user_id', 'attendee_role', 'status', 'arrived_at', 'minutes_late', 'recorded_by_id', 'notes'])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendee_role' => AttendeeRole::class,
            'status' => AttendanceStatus::class,
            'arrived_at' => 'immutable_datetime',
            'minutes_late' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id')->withTrashed();
    }

    /**
     * @return HasOne<LatenessReport, $this>
     */
    public function latenessReport(): HasOne
    {
        return $this->hasOne(LatenessReport::class);
    }
}
```

`app/Models/LatenessReport.php`:

```php
<?php

namespace App\Models;

use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot created whenever someone is late, so the history survives schedule changes.
 * Only App\Actions\RecordAttendance writes these.
 *
 * @property int $id
 * @property int $attendance_record_id
 * @property int $user_id
 * @property AttendeeRole $attendee_role
 * @property int $lesson_id
 * @property string $class_name
 * @property CarbonImmutable $lesson_date
 * @property CarbonImmutable $scheduled_start_at
 * @property CarbonImmutable $arrived_at
 * @property int $minutes_late
 * @property string|null $reason
 * @property int|null $reviewed_by_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $follow_up_notes
 */
#[Fillable(['attendance_record_id', 'user_id', 'attendee_role', 'lesson_id', 'class_name', 'lesson_date', 'scheduled_start_at', 'arrived_at', 'minutes_late', 'reason', 'reviewed_by_id', 'reviewed_at', 'follow_up_notes'])]
class LatenessReport extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendee_role' => AttendeeRole::class,
            'lesson_date' => 'immutable_date',
            'scheduled_start_at' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
            'minutes_late' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id')->withTrashed();
    }
}
```

`database/factories/AttendanceRecordFactory.php` `definition()`:

```php
return [
    'lesson_id' => Lesson::factory(),
    'user_id' => User::factory()->student(),
    'attendee_role' => AttendeeRole::Student,
    'status' => AttendanceStatus::Present,
    'arrived_at' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])->starts_at,
    'minutes_late' => null,
    'recorded_by_id' => null,
    'notes' => null,
];
```

(imports `App\Enums\AttendanceStatus`, `App\Enums\AttendeeRole`, `App\Models\Lesson`, `App\Models\User`)

In `app/Models/Lesson.php`, change `tutor()` to `return $this->belongsTo(User::class, 'tutor_id')->withTrashed();`. A past lesson's tutor may since have been deactivated (3a review fix: deactivation removes them from classes), and registers and the no-show job must still see them. Then add `use Illuminate\Database\Eloquent\Relations\HasMany;` and:

```php
/**
 * @return HasMany<AttendanceRecord, $this>
 */
public function attendanceRecords(): HasMany
{
    return $this->hasMany(AttendanceRecord::class);
}

/**
 * @return HasMany<LatenessReport, $this>
 */
public function latenessReports(): HasMany
{
    return $this->hasMany(LatenessReport::class);
}

public function localStart(): CarbonImmutable
{
    return $this->starts_at->setTimezone(config('academy.timezone'));
}

public function localEnd(): CarbonImmutable
{
    return $this->ends_at->setTimezone(config('academy.timezone'));
}

/**
 * Read an "HH:MM" clock time on the lesson's local date and return it in UTC.
 */
public function localTimeToUtc(string $time): CarbonImmutable
{
    return $this->localStart()->setTimeFromTimeString($time)->utc();
}

public function isCancelled(): bool
{
    return $this->status === LessonStatus::Cancelled;
}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/AttendanceModelTest.php
```

Expected: PASS (4 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: attendance records and lateness reports schema"
```

---

### Task 2: The `RecordAttendance` action

**Files:**

- Create: `app/Actions/RecordAttendance.php`
- Modify: `app/Services/LessonScheduler.php`
- Test: `tests/Feature/RecordAttendanceTest.php`, `tests/Feature/LessonSchedulerTest.php`

**Interfaces:**

- Consumes: Task 1 models and `config('academy.lateness_grace_minutes')`.
- Produces:
    - `RecordAttendance::handle(Lesson $lesson, User $attendee, AttendanceStatus $status, ?User $recordedBy, ?CarbonImmutable $arrivedAt = null, ?string $notes = null): AttendanceRecord`, where `$status` is `Present`, `Absent` or `Excused`. Passing `Late` throws `InvalidArgumentException`, and so does an attendee who isn't the lesson's tutor or an (ever-)enrolled student.
    - **Present without an arrival time** means they arrived at the start.
    - **Lateness** is calculated from the arrival time and the grace period, and the matching report is synced.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest RecordAttendanceTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->student = User::factory()->student()->create();
    $this->group->enrol($this->student);
    $this->tutor = $this->lesson->tutor;
    $this->record = fn (User $who, AttendanceStatus $status, ?string $arrivedUtc = null) => app(RecordAttendance::class)
        ->handle($this->lesson, $who, $status, $this->tutor, $arrivedUtc ? CarbonImmutable::parse($arrivedUtc, 'UTC') : null);
});

it('treats arriving at the start, or within the same minute, as on time', function (string $arrival) {
    $record = ($this->record)($this->student, AttendanceStatus::Present, $arrival);

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->minutes_late)->toBeNull()
        ->and(LatenessReport::count())->toBe(0);
})->with(['2025-10-04 09:00:00', '2025-10-04 09:00:59']);

it('marks someone a minute or more late and writes a lateness report', function () {
    $record = ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:07:30');

    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->minutes_late)->toBe(7)
        ->and($record->attendee_role)->toBe(AttendeeRole::Student);

    $report = $record->latenessReport;
    expect($report->user_id)->toBe($this->student->id)
        ->and($report->attendee_role)->toBe(AttendeeRole::Student)
        ->and($report->class_name)->toBe('Year 5 Maths')
        ->and($report->lesson_date->toDateString())->toBe('2025-10-04')
        ->and($report->scheduled_start_at->toDateTimeString())->toBe('2025-10-04 09:00:00')
        ->and($report->arrived_at->toDateTimeString())->toBe('2025-10-04 09:07:30')
        ->and($report->minutes_late)->toBe(7);
});

it('applies exactly the same rules to the tutor', function () {
    $record = ($this->record)($this->tutor, AttendanceStatus::Present, '2025-10-04 09:01:00');

    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->attendee_role)->toBe(AttendeeRole::Tutor)
        ->and($record->latenessReport->attendee_role)->toBe(AttendeeRole::Tutor);
});

it('respects the grace period', function () {
    config(['academy.lateness_grace_minutes' => 5]);

    expect(($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:04:00')->status)->toBe(AttendanceStatus::Present)
        ->and(($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:06:00')->status)->toBe(AttendanceStatus::Late);
});

it('defaults a present arrival to the lesson start', function () {
    $record = ($this->record)($this->student, AttendanceStatus::Present);

    expect($record->arrived_at->toDateTimeString())->toBe('2025-10-04 09:00:00')
        ->and($record->status)->toBe(AttendanceStatus::Present);
});

it('keeps one record and one report, updating them on correction', function () {
    ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:10:00');
    $record = ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:03:00');

    expect($this->lesson->attendanceRecords()->count())->toBe(1)
        ->and(LatenessReport::count())->toBe(1)
        ->and($record->latenessReport->minutes_late)->toBe(3);
});

it('removes the report when someone is corrected to on time, absent or excused', function (AttendanceStatus $correction) {
    ($this->record)($this->student, AttendanceStatus::Present, '2025-10-04 09:10:00');

    $record = ($this->record)($this->student, $correction, $correction === AttendanceStatus::Present ? '2025-10-04 09:00:00' : null);

    expect($record->status)->toBe($correction)
        ->and(LatenessReport::count())->toBe(0);
    if ($correction !== AttendanceStatus::Present) {
        expect($record->arrived_at)->toBeNull()->and($record->minutes_late)->toBeNull();
    }
})->with([AttendanceStatus::Present, AttendanceStatus::Absent, AttendanceStatus::Excused]);

it('records who made the entry, or null for the system', function () {
    expect(($this->record)($this->student, AttendanceStatus::Absent)->recorded_by_id)->toBe($this->tutor->id);

    $system = app(RecordAttendance::class)->handle($this->lesson, $this->student, AttendanceStatus::Absent, null);
    expect($system->recorded_by_id)->toBeNull();
});

it('refuses people who are not part of the lesson', function () {
    ($this->record)(User::factory()->student()->create(), AttendanceStatus::Present);
})->throws(InvalidArgumentException::class);

it('still accepts a student who has since left the class, for corrections', function () {
    $this->group->withdraw($this->student);

    expect(($this->record)($this->student, AttendanceStatus::Absent)->status)->toBe(AttendanceStatus::Absent);
});

it('refuses Late as an input, because lateness is worked out', function () {
    ($this->record)($this->student, AttendanceStatus::Late, '2025-10-04 09:10:00');
})->throws(InvalidArgumentException::class);
```

Append to `tests/Feature/LessonSchedulerTest.php`:

```php
it('never deletes a future lesson that already has attendance', function () {
    $group = ClassGroup::factory()->create(['weekday' => Weekday::Saturday, 'start_time' => '10:00']);
    app(LessonScheduler::class)->generateFor($group);
    $lesson = $group->lessons()->orderBy('starts_at')->first();
    \App\Models\AttendanceRecord::factory()->create(['lesson_id' => $lesson->id, 'user_id' => $group->tutor_id, 'attendee_role' => \App\Enums\AttendeeRole::Tutor]);

    $group->update(['weekday' => Weekday::Sunday]);
    app(LessonScheduler::class)->rescheduleFuture($group);

    expect($lesson->fresh())->not->toBeNull();
});
```

Import `App\Models\AttendanceRecord` and `App\Enums\AttendeeRole` at the top of the file instead of using FQCNs.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/RecordAttendanceTest.php tests/Feature/LessonSchedulerTest.php`
Expected: FAIL. `App\Actions\RecordAttendance` isn't found, and the scheduler test deletes the lesson.

- [ ] **Step 3: The action**

`app/Actions/RecordAttendance.php`:

```php
<?php

namespace App\Actions;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\AttendanceRecord;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place attendance is written. Callers say whether someone attended
 * (Present + arrival time), was Absent or Excused; lateness is worked out here
 * and the person's lateness report is created, updated or removed to match.
 * Students and tutors follow exactly the same rules.
 */
class RecordAttendance
{
    /**
     * @throws InvalidArgumentException
     */
    public function handle(
        Lesson $lesson,
        User $attendee,
        AttendanceStatus $status,
        ?User $recordedBy,
        ?CarbonImmutable $arrivedAt = null,
        ?string $notes = null,
    ): AttendanceRecord {
        if ($status === AttendanceStatus::Late) {
            throw new InvalidArgumentException('Record Present with an arrival time; lateness is worked out from it.');
        }

        $attendeeRole = $this->attendeeRoleFor($lesson, $attendee);

        $arrivedAt = $status === AttendanceStatus::Present ? ($arrivedAt ?? $lesson->starts_at) : null;
        $minutesLate = $arrivedAt === null ? 0 : max(0, (int) floor($lesson->starts_at->diffInSeconds($arrivedAt, false) / 60));
        $isLate = $arrivedAt !== null && $minutesLate > (int) config('academy.lateness_grace_minutes');

        return DB::transaction(function () use ($lesson, $attendee, $attendeeRole, $status, $recordedBy, $arrivedAt, $minutesLate, $isLate, $notes): AttendanceRecord {
            $record = AttendanceRecord::query()->updateOrCreate(
                ['lesson_id' => $lesson->id, 'user_id' => $attendee->id],
                [
                    'attendee_role' => $attendeeRole,
                    'status' => $isLate ? AttendanceStatus::Late : $status,
                    'arrived_at' => $arrivedAt,
                    'minutes_late' => $isLate ? $minutesLate : null,
                    'recorded_by_id' => $recordedBy?->id,
                    'notes' => $notes,
                ],
            );

            $this->syncLatenessReport($record, $lesson);

            return $record->load('latenessReport');
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function attendeeRoleFor(Lesson $lesson, User $attendee): AttendeeRole
    {
        if ($attendee->id === $lesson->tutor_id) {
            return AttendeeRole::Tutor;
        }

        if ($lesson->classGroup->students()->whereKey($attendee->id)->exists()) {
            return AttendeeRole::Student;
        }

        throw new InvalidArgumentException('Only the lesson\'s tutor and the class\'s students can be recorded.');
    }

    private function syncLatenessReport(AttendanceRecord $record, Lesson $lesson): void
    {
        if ($record->status !== AttendanceStatus::Late) {
            LatenessReport::query()->where('attendance_record_id', $record->id)->delete();

            return;
        }

        LatenessReport::query()->updateOrCreate(
            ['attendance_record_id' => $record->id],
            [
                'user_id' => $record->user_id,
                'attendee_role' => $record->attendee_role,
                'lesson_id' => $lesson->id,
                'class_name' => $lesson->classGroup->name,
                'lesson_date' => $lesson->localStart()->toDateString(),
                'scheduled_start_at' => $lesson->starts_at,
                'arrived_at' => $record->arrived_at,
                'minutes_late' => $record->minutes_late,
            ],
        );
    }
}
```

`diffInSeconds($other, false)` is signed in Carbon 3, and negative means early. Early arrivals count as 0, so on time.

- [ ] **Step 4: The scheduler guard**

In `LessonScheduler::rescheduleFuture()`, add `->whereDoesntHave('attendanceRecords')` to the delete query, before `->delete()`. Update the method's docblock to say "…that have no attendance yet".

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/RecordAttendanceTest.php tests/Feature/LessonSchedulerTest.php`
Expected: PASS (2 + 1 + 1 + 1 + 1 + 1 + 3 + 1 + 1 + 1 + 1 = 14, plus 9 = 23).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: record attendance and lateness with one action"
```

---

### Task 3: Lesson policy, tutor "Today" dashboard and check-in

**Files:**

- Create: `app/Policies/LessonPolicy.php`, `app/Http/Controllers/TutorCheckInController.php`
- Modify: `app/Http/Controllers/DashboardController.php`, `routes/web.php`, `resources/js/pages/dashboard/tutor.tsx`
- Test: `tests/Feature/LessonPolicyTest.php`, `tests/Feature/TutorCheckInTest.php`

**Interfaces:**

- Consumes: `RecordAttendance`, `Lesson` helpers, `Role`.
- Produces:
    - **`LessonPolicy`:** `checkIn(User, Lesson)`, `takeRegister(User, Lesson)`, `recordTutorAttendance(User, Lesson)`.
    - **Route** `lessons.check-in` (`POST /lessons/{lesson}/check-in`, `auth` + `verified`).
    - **Tutor dashboard props:**
        - `today`: `{ id, className, time, location, status, checkedInAt: string|null, late: bool, students: int, canCheckIn: bool }[]`;
        - `upcoming`: `{ id, className, when }[]`, for the next 5 lessons after today.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest LessonPolicyTest --no-interaction`:

```php
<?php

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->lesson = Lesson::factory()->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->tutor = $this->lesson->tutor;
});

it('lets the lesson\'s tutor act from 30 minutes before until a day after', function (string $now, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    expect($this->tutor->can('checkIn', $this->lesson))->toBe($allowed)
        ->and($this->tutor->can('takeRegister', $this->lesson))->toBe($allowed);
})->with([
    'a week early' => ['2025-09-27 09:00:00', false],
    '31 minutes early' => ['2025-10-04 08:29:00', false],
    '30 minutes early' => ['2025-10-04 08:30:00', true],
    'during' => ['2025-10-04 09:30:00', true],
    'next morning' => ['2025-10-05 09:00:00', true],
    'two days later' => ['2025-10-06 10:01:00', false],
]);

it('never lets another tutor, a guardian or a student act', function (string $state) {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'));
    $someone = User::factory()->{$state}()->create();

    expect($someone->can('checkIn', $this->lesson))->toBeFalse()
        ->and($someone->can('takeRegister', $this->lesson))->toBeFalse()
        ->and($someone->can('recordTutorAttendance', $this->lesson))->toBeFalse();
})->with(['tutor', 'guardian', 'student']);

it('lets admins take any register and record the tutor, at any time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-01 09:00:00', 'UTC'));
    $admin = User::factory()->admin()->create();

    expect($admin->can('takeRegister', $this->lesson))->toBeTrue()
        ->and($admin->can('recordTutorAttendance', $this->lesson))->toBeTrue()
        ->and($admin->can('checkIn', $this->lesson))->toBeFalse()
        ->and($this->tutor->can('recordTutorAttendance', $this->lesson))->toBeFalse();
});

it('blocks everyone from a cancelled lesson', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'));
    $this->lesson->update(['status' => LessonStatus::Cancelled]);

    expect($this->tutor->can('takeRegister', $this->lesson))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('takeRegister', $this->lesson))->toBeFalse();
});
```

`php artisan make:test --pest TutorCheckInTest --no-interaction`:

```php
<?php

use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->tutor = User::factory()->tutor()->create();
    $this->group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 5 Maths', 'location' => 'Room 1']);
    $this->group->enrol(User::factory()->student()->create());
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

it('shows the tutor today\'s lessons in academy time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:45:00', 'UTC'));
    Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-11 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-11 10:00:00', 'UTC'),
    ]);

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/tutor')
            ->has('today', 1)
            ->where('today.0.className', 'Year 5 Maths')
            ->where('today.0.time', '10:00–11:00')
            ->where('today.0.location', 'Room 1')
            ->where('today.0.students', 1)
            ->where('today.0.checkedInAt', null)
            ->where('today.0.canCheckIn', true)
            ->has('upcoming', 1)
            ->where('upcoming.0.when', 'Sat 11 Oct, 10:00'));
});

it('checks the tutor in on time', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:58:00', 'UTC'));

    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson))
        ->assertRedirect(route('lessons.register.show', $this->lesson));

    $record = $this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first();
    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::InProgress);
});

it('records a late check-in and its lateness report', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:12:00', 'UTC'));

    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson));

    $record = $this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first();
    expect($record->status)->toBe(AttendanceStatus::Late)
        ->and($record->minutes_late)->toBe(12)
        ->and($record->latenessReport)->not->toBeNull();

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.0.checkedInAt', '10:12')
            ->where('today.0.late', true));
});

it('keeps the first check-in time if the tutor presses it again', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:58:00', 'UTC'));
    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson));

    $this->travelTo(CarbonImmutable::parse('2025-10-04 09:20:00', 'UTC'));
    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson));

    expect($this->lesson->attendanceRecords()->where('user_id', $this->tutor->id)->first()->status)
        ->toBe(AttendanceStatus::Present);
});

it('refuses check-in for someone else\'s lesson or too early', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 08:58:00', 'UTC'));
    $this->actingAs(User::factory()->tutor()->create())->post(route('lessons.check-in', $this->lesson))->assertForbidden();

    $this->travelTo(CarbonImmutable::parse('2025-10-03 09:00:00', 'UTC'));
    $this->actingAs($this->tutor)->post(route('lessons.check-in', $this->lesson))->assertForbidden();

    expect($this->lesson->attendanceRecords()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/LessonPolicyTest.php tests/Feature/TutorCheckInTest.php`
Expected: FAIL. With no policy, `can()` is always false, so the "allowed" cases fail, and `Route [lessons.check-in] not defined`.

- [ ] **Step 3: Policy**

`php artisan make:policy LessonPolicy --model=Lesson --no-interaction`, then replace the class body:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

class LessonPolicy
{
    /**
     * Only the lesson's own tutor checks in, inside their window.
     */
    public function checkIn(User $user, Lesson $lesson): bool
    {
        return ! $lesson->isCancelled()
            && $lesson->tutor_id === $user->id
            && $this->withinTutorWindow($lesson);
    }

    /**
     * The lesson's tutor (inside their window) or any admin (decision D4: admins override).
     */
    public function takeRegister(User $user, Lesson $lesson): bool
    {
        if ($lesson->isCancelled()) {
            return false;
        }

        if ($user->hasRole(Role::Admin)) {
            return true;
        }

        return $lesson->tutor_id === $user->id && $this->withinTutorWindow($lesson);
    }

    /**
     * Tutors record themselves only through check-in; correcting a tutor's record is an admin job.
     */
    public function recordTutorAttendance(User $user, Lesson $lesson): bool
    {
        return ! $lesson->isCancelled() && $user->hasRole(Role::Admin);
    }

    private function withinTutorWindow(Lesson $lesson): bool
    {
        $now = CarbonImmutable::now();

        return $now->greaterThanOrEqualTo($lesson->starts_at->subMinutes(30))
            && $now->lessThanOrEqualTo($lesson->ends_at->addDay());
    }
}
```

Laravel auto-discovers `App\Policies\LessonPolicy` for `App\Models\Lesson`, so no registration is needed.

- [ ] **Step 4: Check-in controller and route**

`php artisan make:controller TutorCheckInController --invokable --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TutorCheckInController extends Controller
{
    /**
     * The tutor checks themselves in; the first check-in time is kept.
     */
    public function __invoke(Request $request, Lesson $lesson, RecordAttendance $recordAttendance): RedirectResponse
    {
        Gate::authorize('checkIn', $lesson);

        $alreadyIn = $lesson->attendanceRecords()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('arrived_at')
            ->exists();

        if (! $alreadyIn) {
            $record = $recordAttendance->handle($lesson, $request->user(), AttendanceStatus::Present, $request->user(), CarbonImmutable::now());

            if ($lesson->status === LessonStatus::Scheduled) {
                $lesson->update(['status' => LessonStatus::InProgress]);
            }

            $message = $record->status === AttendanceStatus::Late
                ? "Checked in {$record->minutes_late} min after the start."
                : 'Checked in on time.';
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return to_route('lessons.register.show', $lesson);
    }
}
```

In `routes/web.php`, inside the existing `Route::middleware(['auth', 'verified'])` group (add `use App\Http\Controllers\TutorCheckInController;`):

```php
Route::post('/lessons/{lesson}/check-in', TutorCheckInController::class)->name('lessons.check-in');
```

The tests redirect to `lessons.register.show`. Task 4 defines that route, so add a temporary placeholder now so Task 3's tests can pass:

```php
Route::get('/lessons/{lesson}/register', fn () => abort(404))->name('lessons.register.show');
```

Task 4 replaces it with the real route. Ledger it.

- [ ] **Step 5: Tutor dashboard data**

Replace `DashboardController::__invoke` (add `use App\Enums\Role;`, `use App\Enums\AttendanceStatus;`, `use App\Models\Lesson;`, `use App\Models\User;` and `use Carbon\CarbonImmutable;`):

```php
public function __invoke(Request $request): Response
{
    $user = $request->user();

    return Inertia::render('dashboard/'.$user->role->value, match ($user->role) {
        Role::Tutor => $this->tutorToday($user),
        default => [],
    });
}

/**
 * Today's lessons (academy day) plus the next few, for the tutor's "Today" screen.
 *
 * @return array{today: array<int, array<string, mixed>>, upcoming: array<int, array<string, mixed>>}
 */
private function tutorToday(User $tutor): array
{
    $timezone = config('academy.timezone');
    $startOfDay = CarbonImmutable::now($timezone)->startOfDay();
    $endOfDay = $startOfDay->endOfDay();

    $today = $tutor->lessonsTaught()
        ->with(['classGroup' => fn ($query) => $query->withCount('activeStudents'), 'attendanceRecords' => fn ($query) => $query->where('user_id', $tutor->id)])
        ->whereBetween('starts_at', [$startOfDay->utc(), $endOfDay->utc()])
        ->orderBy('starts_at')
        ->get()
        ->map(function (Lesson $lesson) use ($tutor): array {
            $own = $lesson->attendanceRecords->first();

            return [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
                'location' => $lesson->classGroup->location,
                'status' => $lesson->status->value,
                'checkedInAt' => $own?->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i'),
                'late' => $own?->status === AttendanceStatus::Late,
                'students' => $lesson->classGroup->active_students_count,
                'canCheckIn' => $own?->arrived_at === null && $tutor->can('checkIn', $lesson),
            ];
        })
        ->all();

    $upcoming = $tutor->lessonsTaught()
        ->with('classGroup')
        ->where('starts_at', '>', $endOfDay->utc())
        ->where('status', '!=', \App\Enums\LessonStatus::Cancelled)
        ->orderBy('starts_at')
        ->limit(5)
        ->get()
        ->map(fn (Lesson $lesson): array => [
            'id' => $lesson->id,
            'className' => $lesson->classGroup->name,
            'when' => $lesson->localStart()->format('D j M, H:i'),
        ])
        ->all();

    return ['today' => $today, 'upcoming' => $upcoming];
}
```

Import `App\Enums\LessonStatus` instead of using the FQCN. `CarbonImmutable::now($timezone)->startOfDay()` gives local midnight, and `->utc()` converts it for the query.

- [ ] **Step 6: Tutor dashboard page**

Replace `resources/js/pages/dashboard/tutor.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { checkIn } from '@/routes/lessons';
import { show as register } from '@/routes/lessons/register';

type TodayLesson = {
    id: number;
    className: string;
    time: string;
    location: string | null;
    status: string;
    checkedInAt: string | null;
    late: boolean;
    students: number;
    canCheckIn: boolean;
};

type TutorDashboardProps = {
    today: TodayLesson[];
    upcoming: { id: number; className: string; when: string }[];
};

export default function TutorDashboard({
    today,
    upcoming,
}: TutorDashboardProps) {
    return (
        <AppLayout title="Today">
            {today.length === 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle>No lessons today</CardTitle>
                        <CardDescription>
                            Your next lessons are listed below.
                        </CardDescription>
                    </CardHeader>
                    <CardContent />
                </Card>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2">
                    {today.map((lesson) => (
                        <li key={lesson.id}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>{lesson.className}</CardTitle>
                                    <CardDescription>
                                        {lesson.time}
                                        {lesson.location
                                            ? ` · ${lesson.location}`
                                            : ''}{' '}
                                        · {lesson.students} students
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-center gap-3">
                                    {lesson.checkedInAt ? (
                                        <Badge
                                            tone={
                                                lesson.late ? 'late' : 'present'
                                            }
                                        >
                                            Checked in at {lesson.checkedInAt}
                                        </Badge>
                                    ) : lesson.canCheckIn ? (
                                        <Link
                                            href={checkIn(lesson.id)}
                                            as="button"
                                            className="inline-flex h-12 items-center rounded-md bg-accent px-6 text-base font-medium text-accent-foreground shadow-card hover:bg-accent-hover"
                                        >
                                            Check in
                                        </Link>
                                    ) : (
                                        <Badge tone="neutral">
                                            Check-in opens 30 min before
                                        </Badge>
                                    )}
                                    <Link
                                        href={register(lesson.id)}
                                        className="inline-flex h-10 items-center rounded-md border border-input bg-surface px-4 text-sm font-medium hover:bg-surface-muted"
                                    >
                                        Take register
                                        <span className="sr-only">
                                            {' '}
                                            for {lesson.className}
                                        </span>
                                    </Link>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
            {upcoming.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold">Coming up</h2>
                    <ul className="divide-y rounded-lg border bg-surface">
                        {upcoming.map((lesson) => (
                            <li
                                key={lesson.id}
                                className="flex justify-between px-4 py-3 text-sm"
                            >
                                <span className="font-medium">
                                    {lesson.className}
                                </span>
                                <span className="text-muted-foreground">
                                    {lesson.when}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AppLayout>
    );
}
```

If Wayfinder names the check-in export differently (e.g. `checkIn` from `@/routes/lessons` as generated from `lessons.check-in`), adjust the import after running `wayfinder:generate` and ledger it.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/LessonPolicyTest.php tests/Feature/TutorCheckInTest.php tests/Feature/DashboardTest.php
```

Expected: PASS (6 + 3 + 1 + 1 = 11 policy, 5 check-in, plus the existing DashboardTest).

- [ ] **Step 8: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
git add -A && git commit -m "feat: tutor today screen and check-in"
```

---

### Task 4: The register (tutor) and admin override

**Files:**

- Create: `app/Http/Controllers/RegisterController.php`, `app/Http/Requests/UpdateRegisterRequest.php`, `resources/js/pages/lessons/register.tsx`, `resources/js/components/register-row.tsx`
- Modify: `routes/web.php` (replace the placeholder), `app/Http/Controllers/Admin/ClassGroupController.php` (`edit` adds `lessons`), `resources/js/pages/admin/classes/edit.tsx`
- Test: `tests/Feature/RegisterTest.php`

**Interfaces:**

- Consumes: `LessonPolicy`, `RecordAttendance`, the `Lesson` helpers, `AttendanceStatusBadge`.
- Produces:
    - **Routes:** `lessons.register.show` (`GET`) and `lessons.register.update` (`PUT /lessons/{lesson}/register`).
    - **The PUT body:** `attendance[{userId}][status]`, which is one of `present|late|absent|excused`. Here `late` means "attended, enter a time"; the stored status is always calculated. Each entry also takes `attendance[{userId}][arrived_at]` (`HH:MM`, required when `late`) and `attendance[{userId}][notes]`.
    - **Register props:**
        - `lesson`: `{ id, className, date, time, location, status }`;
        - `tutor`: `Row`;
        - `students`: `Row[]`;
        - `canEditTutor`: bool;
        - `statuses`: `Option[]`.

        A `Row` is `{ id, name, yearGroup, status: string|null, arrivedAt: string|null, minutesLate: number|null, notes: string|null }`.

    - **Admin class edit props** gain `lessons: { id, when, status, recorded: number }[]` (the last 4 and the next 4).

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest RegisterTest --no-interaction`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/RegisterTest.php`
Expected: FAIL. The placeholder route returns 404, and `lessons.register.update` isn't defined.

- [ ] **Step 3: Routes**

Replace the Task 3 placeholder in `routes/web.php` (add `use App\Http\Controllers\RegisterController;`):

```php
Route::get('/lessons/{lesson}/register', [RegisterController::class, 'show'])->name('lessons.register.show');
Route::put('/lessons/{lesson}/register', [RegisterController::class, 'update'])->name('lessons.register.update');
```

- [ ] **Step 4: Form request**

`php artisan make:request UpdateRegisterRequest --no-interaction`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('takeRegister', $this->route('lesson'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'attendance' => ['required', 'array', 'min:1'],
            'attendance.*.status' => ['required', 'in:present,late,absent,excused'],
            'attendance.*.arrived_at' => ['nullable', 'required_if:attendance.*.status,late', 'date_format:H:i'],
            'attendance.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Every key must be someone on this register: an (ever-)enrolled student, or the tutor if the user may edit the tutor.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Lesson $lesson */
                $lesson = $this->route('lesson');
                $ids = array_map('intval', array_keys((array) $this->input('attendance', [])));

                $allowed = $lesson->classGroup->students()->pluck('users.id')->all();
                if ($this->user()->can('recordTutorAttendance', $lesson)) {
                    $allowed[] = $lesson->tutor_id;
                }

                if (array_diff($ids, $allowed) !== []) {
                    $validator->errors()->add('attendance', 'The register can only include this lesson\'s students'.($this->user()->can('recordTutorAttendance', $lesson) ? ' and tutor.' : '.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attendance.*.arrived_at.required_if' => 'Enter the time they arrived.',
            'attendance.*.arrived_at.date_format' => 'Use a time like 10:05.',
            'attendance.*.status.in' => 'Choose on time, late, absent or excused.',
        ];
    }
}
```

- [ ] **Step 5: Controller**

`php artisan make:controller RegisterController --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Http\Requests\UpdateRegisterRequest;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function show(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('takeRegister', $lesson);

        $lesson->load(['classGroup', 'tutor', 'attendanceRecords']);
        $records = $lesson->attendanceRecords->keyBy('user_id');

        $studentIds = $lesson->classGroup->activeStudents()->pluck('users.id')
            ->merge($records->keys())
            ->reject(fn (int $id): bool => $id === $lesson->tutor_id)
            ->unique();

        $students = User::withTrashed()
            ->whereKey($studentIds)
            ->with('studentProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $student): array => $this->row($student, $records->get($student->id)))
            ->values();

        return Inertia::render('lessons/register', [
            'lesson' => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'date' => $lesson->localStart()->format('l j F'),
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
                'location' => $lesson->classGroup->location,
                'status' => $lesson->status->value,
            ],
            'tutor' => $this->row($lesson->tutor, $records->get($lesson->tutor_id)),
            'students' => $students,
            'canEditTutor' => $request->user()->can('recordTutorAttendance', $lesson),
            'statuses' => [
                ['value' => 'present', 'label' => 'On time'],
                ['value' => 'late', 'label' => 'Late'],
                ['value' => 'absent', 'label' => 'Absent'],
                ['value' => 'excused', 'label' => 'Excused'],
            ],
        ]);
    }

    public function update(UpdateRegisterRequest $request, Lesson $lesson, RecordAttendance $recordAttendance): RedirectResponse
    {
        $people = User::withTrashed()->whereKey(array_keys($request->validated('attendance')))->get()->keyBy('id');

        DB::transaction(function () use ($request, $lesson, $recordAttendance, $people): void {
            foreach ($request->validated('attendance') as $userId => $entry) {
                $attended = in_array($entry['status'], ['present', 'late'], true);

                $recordAttendance->handle(
                    $lesson,
                    $people[(int) $userId],
                    $attended ? AttendanceStatus::Present : AttendanceStatus::from($entry['status']),
                    $request->user(),
                    $entry['status'] === 'late' ? $lesson->localTimeToUtc($entry['arrived_at']) : null,
                    $entry['notes'] ?? null,
                );
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Register saved.']);

        return to_route('lessons.register.show', $lesson);
    }

    /**
     * @return array{id: int, name: string, yearGroup: string|null, status: string|null, arrivedAt: string|null, minutesLate: int|null, notes: string|null}
     */
    private function row(User $person, ?AttendanceRecord $record): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'yearGroup' => $person->studentProfile?->year_group->label(),
            'status' => $record?->status->value,
            'arrivedAt' => $record?->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i'),
            'minutesLate' => $record?->minutes_late,
            'notes' => $record?->notes,
        ];
    }
}
```

**Re-saving a row as "present":** an on-time student's `arrived_at` resets to the start, which is correct. A previously late student re-saved as "present" is deliberately corrected to on time.

**Row order:** names are ordered, so "Ava" is `students.0` in the tests.

`$lesson->tutor` can be null only if the tutor was force-deleted. `lessons.tutor_id` has no `nullOnDelete`, so that can't happen.

- [ ] **Step 6: Register UI**

`resources/js/components/register-row.tsx`:

```tsx
import { useState } from 'react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import type { Option } from '@/types';

export type RegisterPerson = {
    id: number;
    name: string;
    yearGroup: string | null;
    status: string | null;
    arrivedAt: string | null;
    minutesLate: number | null;
    notes: string | null;
};

type RegisterRowProps = {
    person: RegisterPerson;
    statuses: Option[];
    errors: Record<string, string | undefined>;
    defaultTime: string;
};

export default function RegisterRow({
    person,
    statuses,
    errors,
    defaultTime,
}: RegisterRowProps) {
    const [status, setStatus] = useState(person.status ?? '');
    const field = (name: string) => `attendance[${person.id}][${name}]`;
    const errorKey = (name: string) => `attendance.${person.id}.${name}`;

    return (
        <fieldset className="flex flex-col gap-3 border-b py-4 last:border-0">
            <legend className="sr-only">{person.name}</legend>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-medium">{person.name}</p>
                    {person.yearGroup && (
                        <p className="text-xs text-muted-foreground">
                            {person.yearGroup}
                        </p>
                    )}
                </div>
                {person.status && (
                    <AttendanceStatusBadge
                        status={person.status as AttendanceStatus}
                        minutesLate={person.minutesLate}
                    />
                )}
            </div>
            <div
                className="flex flex-wrap gap-2"
                role="radiogroup"
                aria-label={`Attendance for ${person.name}`}
            >
                {statuses.map((option) => (
                    <label
                        key={option.value}
                        className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-md border border-input px-3 text-sm has-checked:border-primary has-checked:bg-royal-50 dark:has-checked:bg-royal-900/40"
                    >
                        <input
                            type="radio"
                            name={field('status')}
                            value={option.value}
                            checked={status === option.value}
                            onChange={() => setStatus(String(option.value))}
                            className="accent-primary"
                        />
                        {option.label}
                    </label>
                ))}
            </div>
            <FieldError
                id={`${person.id}-status-error`}
                message={errors[errorKey('status')]}
            />
            {status === 'late' && (
                <div className="flex max-w-48 flex-col gap-1.5">
                    <label
                        htmlFor={`${person.id}-arrived`}
                        className="text-sm font-medium"
                    >
                        Arrived at
                    </label>
                    <Input
                        id={`${person.id}-arrived`}
                        name={field('arrived_at')}
                        type="time"
                        defaultValue={person.arrivedAt ?? defaultTime}
                        required
                        aria-invalid={!!errors[errorKey('arrived_at')]}
                        aria-describedby={`${person.id}-arrived-error`}
                    />
                    <FieldError
                        id={`${person.id}-arrived-error`}
                        message={errors[errorKey('arrived_at')]}
                    />
                </div>
            )}
            <div className="flex flex-col gap-1.5">
                <label
                    htmlFor={`${person.id}-notes`}
                    className="text-sm font-medium text-muted-foreground"
                >
                    Notes (optional)
                </label>
                <Input
                    id={`${person.id}-notes`}
                    name={field('notes')}
                    defaultValue={person.notes ?? ''}
                    aria-describedby={`${person.id}-notes-error`}
                />
                <FieldError
                    id={`${person.id}-notes-error`}
                    message={errors[errorKey('notes')]}
                />
            </div>
        </fieldset>
    );
}
```

`has-checked:` is a Tailwind v4 variant. If `has-checked` isn't supported, use `has-[:checked]:` and ledger it. A row with no status chosen sends no `status`. The server then fails `required` for that id only if the id is present, and an id with no status field is absent entirely, so unmarked rows are skipped. Confirm this with the tests; that's why only marked people are posted.

`resources/js/pages/lessons/register.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import RegisterRow from '@/components/register-row';
import type { RegisterPerson } from '@/components/register-row';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/lessons/register';
import type { Option } from '@/types';

type RegisterProps = {
    lesson: {
        id: number;
        className: string;
        date: string;
        time: string;
        location: string | null;
        status: string;
    };
    tutor: RegisterPerson;
    students: RegisterPerson[];
    canEditTutor: boolean;
    statuses: Option[];
};

export default function Register({
    lesson,
    tutor,
    students,
    canEditTutor,
    statuses,
}: RegisterProps) {
    const startTime = lesson.time.split('–')[0];

    return (
        <AppLayout title={`Register · ${lesson.className}`}>
            <p className="text-muted-foreground">
                {lesson.date}, {lesson.time}
                {lesson.location ? ` · ${lesson.location}` : ''}
            </p>
            <Form {...update.form(lesson.id)} className="flex flex-col gap-6">
                {({ errors, processing }) => (
                    <>
                        <FieldError
                            id="attendance-error"
                            message={errors.attendance}
                        />
                        <Card>
                            <CardHeader>
                                <CardTitle>Tutor</CardTitle>
                                <CardDescription>
                                    Tutors check in from their Today screen.
                                    Admins can correct the tutor's record here.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {canEditTutor ? (
                                    <RegisterRow
                                        person={tutor}
                                        statuses={statuses}
                                        errors={errors}
                                        defaultTime={startTime}
                                    />
                                ) : (
                                    <div className="flex items-center justify-between">
                                        <span className="font-medium">
                                            {tutor.name}
                                        </span>
                                        {tutor.status ? (
                                            <AttendanceStatusBadge
                                                status={
                                                    tutor.status as AttendanceStatus
                                                }
                                                minutesLate={tutor.minutesLate}
                                            />
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                Not checked in
                                            </span>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    Students ({students.length})
                                </CardTitle>
                                <CardDescription>
                                    Mark each student. For a late arrival, enter
                                    the time they arrived; lateness is worked
                                    out automatically.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {students.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No students are enrolled in this class.
                                    </p>
                                ) : (
                                    students.map((student) => (
                                        <RegisterRow
                                            key={student.id}
                                            person={student}
                                            statuses={statuses}
                                            errors={errors}
                                            defaultTime={startTime}
                                        />
                                    ))
                                )}
                            </CardContent>
                        </Card>
                        <div>
                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing}
                            >
                                {processing ? 'Saving…' : 'Save register'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}
```

Export `AttendanceStatus` from `resources/js/components/attendance-status-badge.tsx` (it already exports the type).

- [ ] **Step 7: Admin lessons list on the class edit page**

In `ClassGroupController::edit()`, add:

```php
'lessons' => $classGroup->lessons()
    ->withCount('attendanceRecords')
    ->where('starts_at', '<=', now())
    ->orderByDesc('starts_at')
    ->limit(4)
    ->get()
    ->reverse()
    ->merge($classGroup->lessons()->withCount('attendanceRecords')->where('starts_at', '>', now())->orderBy('starts_at')->limit(4)->get())
    ->map(fn (Lesson $lesson): array => [
        'id' => $lesson->id,
        'when' => $lesson->localStart()->format('D j M, H:i'),
        'status' => $lesson->status->value,
        'recorded' => $lesson->attendance_records_count,
    ])
    ->values(),
```

(add `use App\Models\Lesson;`)

In `admin/classes/edit.tsx`, add a third card, "Lessons", with `lessons: { id: number; when: string; status: string; recorded: number }[]` in the props and `show as register` from `@/routes/lessons/register`:

```tsx
<Card className="lg:col-span-2">
    <CardHeader>
        <CardTitle>Lessons</CardTitle>
        <CardDescription>
            Recent and upcoming lessons. Open a register to view or correct
            attendance.
        </CardDescription>
    </CardHeader>
    <CardContent>
        {lessons.length === 0 ? (
            <p className="text-sm text-muted-foreground">
                No lessons scheduled. Give the class a day, time and tutor to
                schedule lessons.
            </p>
        ) : (
            <ul className="divide-y">
                {lessons.map((lesson) => (
                    <li
                        key={lesson.id}
                        className="flex items-center justify-between gap-3 py-2 text-sm"
                    >
                        <span className="font-medium">{lesson.when}</span>
                        <span className="text-muted-foreground">
                            {lesson.recorded} recorded
                        </span>
                        <Link
                            href={register(lesson.id)}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Register
                            <span className="sr-only"> for {lesson.when}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        )}
    </CardContent>
</Card>
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/RegisterTest.php tests/Feature/TutorCheckInTest.php tests/Feature/Admin
```

Expected: PASS (RegisterTest: 1 + 1 + 1 + 1 + 2 + 1 + 1 + 3 + 1 = 12, plus the existing tests).

- [ ] **Step 9: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
php artisan test --compact
git add -A && git commit -m "feat: lesson register with admin override"
```

---

### Task 5: Mark no-shows after lessons end

**Files:**

- Create: `app/Console/Commands/MarkNoShows.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/MarkNoShowsTest.php`

**Interfaces:**

- Consumes: `RecordAttendance` (with `$recordedBy = null`), `Lesson`, `LessonStatus`.
- Produces: the command `attendance:mark-no-shows`, scheduled every five minutes. For each `Scheduled`/`InProgress` lesson that ended at least 15 minutes ago, it records `Absent` for the tutor and every active student who has no record, then sets the lesson to `Completed`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest MarkNoShowsTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->group = ClassGroup::factory()->create();
    $this->ava = User::factory()->student()->create();
    $this->bola = User::factory()->student()->create();
    $this->group->enrol($this->ava);
    $this->group->enrol($this->bola);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
});

it('marks unrecorded people absent 15 minutes after the end and closes the lesson', function () {
    app(RecordAttendance::class)->handle($this->lesson, $this->ava, AttendanceStatus::Present, $this->lesson->tutor);
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:15:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    $records = $this->lesson->attendanceRecords()->get()->keyBy('user_id');
    expect($records)->toHaveCount(3)
        ->and($records[$this->ava->id]->status)->toBe(AttendanceStatus::Present)
        ->and($records[$this->bola->id]->status)->toBe(AttendanceStatus::Absent)
        ->and($records[$this->bola->id]->recorded_by_id)->toBeNull()
        ->and($records[$this->lesson->tutor_id]->status)->toBe(AttendanceStatus::Absent)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::Completed);
});

it('waits until 15 minutes after the end', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:14:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($this->lesson->attendanceRecords()->count())->toBe(0)
        ->and($this->lesson->fresh()->status)->toBe(LessonStatus::Scheduled);
});

it('skips cancelled lessons and students who have left', function () {
    $this->group->withdraw($this->bola);
    $cancelled = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 12:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 13:00:00', 'UTC'),
        'status' => LessonStatus::Cancelled,
    ]);
    $this->travelTo(CarbonImmutable::parse('2025-10-05 09:00:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($cancelled->attendanceRecords()->count())->toBe(0)
        ->and($this->lesson->attendanceRecords()->where('user_id', $this->bola->id)->exists())->toBeFalse();
});

it('is safe to run twice', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 11:00:00', 'UTC'));

    $this->artisan('attendance:mark-no-shows');
    $this->artisan('attendance:mark-no-shows')->assertSuccessful();

    expect($this->lesson->attendanceRecords()->count())->toBe(3);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/MarkNoShowsTest.php`
Expected: FAIL. The command `attendance:mark-no-shows` isn't defined.

- [ ] **Step 3: The command**

`php artisan make:command MarkNoShows --no-interaction`, then (matching the Laravel 13 attribute style used by `GenerateLessons`):

```php
<?php

namespace App\Console\Commands;

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:mark-no-shows')]
#[Description('Mark anyone still unrecorded 15 minutes after a lesson ends as absent, and close the lesson')]
class MarkNoShows extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecordAttendance $recordAttendance): int
    {
        $lessons = Lesson::query()
            ->whereIn('status', [LessonStatus::Scheduled, LessonStatus::InProgress])
            ->where('ends_at', '<=', CarbonImmutable::now()->subMinutes(15))
            ->with(['tutor', 'classGroup', 'attendanceRecords'])
            ->get();

        $marked = 0;

        foreach ($lessons as $lesson) {
            $recorded = $lesson->attendanceRecords->pluck('user_id')->all();

            $people = $lesson->classGroup->activeStudents()->get()->prepend($lesson->tutor);

            $people
                ->reject(fn (User $person): bool => in_array($person->id, $recorded, true))
                ->each(function (User $person) use ($lesson, $recordAttendance, &$marked): void {
                    $recordAttendance->handle($lesson, $person, AttendanceStatus::Absent, null);
                    $marked++;
                });

            $lesson->update(['status' => LessonStatus::Completed]);
        }

        $this->info("Closed {$lessons->count()} lessons and marked {$marked} absences.");

        return self::SUCCESS;
    }
}
```

`routes/console.php`, add:

```php
Schedule::command('attendance:mark-no-shows')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/MarkNoShowsTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Full verification and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check && npm run build
php artisan test --compact
php artisan migrate:fresh --seed --no-interaction
git add -A && git commit -m "feat: mark no-shows after lessons end"
```

Then smoke-test over Herd with time travel. Open `php artisan tinker` and move the seeded lesson's `starts_at`/`ends_at` to now: set `starts_at` 5 minutes ago and `ends_at` 55 minutes ahead.

- As `tutor@academy.test`, Today shows the lesson. Check in, which gives "Late" by 5 minutes and a lateness report. Mark Ava late at a given time and save.
- As the admin, open the class, then the lessons list, then the register. Correct the tutor to on time; the report is removed.

Re-seed afterwards.
