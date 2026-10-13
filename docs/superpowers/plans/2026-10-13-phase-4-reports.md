# Phase 4: End-of-class Reports, Lateness Follow-up & Parent Attendance View

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish Module One (attendance and punctuality) from the client brief:

- **End-of-class reports:** after each class, the tutor and every student who attended can each submit feedback and recommendations. Both get the same form, and admins read them all.
- **Lateness follow-up:** admins can filter lateness reports, spot repeat lateness, record a follow-up, and export the reports as CSV.
- **Parents:** they can see their children's attendance and punctuality.

**Architecture:**

- **Reports:** a `lesson_reports` table, with one row per author per lesson. `LessonPolicy::submitReport` decides who may write and when. A single `/lessons/{lesson}/report` page (GET and PUT, an upsert) serves tutors and students.
- **Shared stats:** `AttendanceStats` is extracted from `StudentDashboard`, so the student dashboard, the parent view and the Children list all compute attendance in one place.
- **Admin pages:** these live under `/admin` (`lesson-reports`, `lateness`).
- **Times:** all formatted in the academy timezone on the server.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Wayfinder, Tailwind v4, Pest 5.

**Spec:** client PDF §2.2–2.4 · [docs/architecture/database-schema.md](../../architecture/database-schema.md) §4 (`lateness_reports`, `lesson_reports`) · [docs/plans/implementation-plan.md](../../plans/implementation-plan.md) Phase 4 · [docs/design/design-brief.md](../../design/design-brief.md) §5–6

## Global Constraints

- **Who can write a report:** the lesson's tutor, and students whose attendance for that lesson is `Present` or `Late`. Both have **identical fields and abilities** (PDF §2.3): `rating` (1–5, optional), `went_well`, `issues`, `recommendations` (each optional text up to 2000 characters, with at least one of the four filled in).
- **When:** a report can be written and edited from the lesson's start until **48 hours after it ends**. Nobody can report on a cancelled lesson, and admins never author reports.
- **Who can read a report** (my ruling, flagged to the user): only its author and admins. Tutors don't see students' reports and students don't see tutors' reports, so the academy gets two independent points of view.
- **Repeat lateness** means **3 or more** lateness reports for one person with `lesson_date` in the last **30 days** (academy timezone). Students and tutors are listed on the same terms.
- **CSV export:** the same filters as the list, streamed. Cells starting with `=`, `+`, `-` or `@` are prefixed with `'` to prevent formula injection. Times are in the academy timezone, labelled in the header.
- **Parents** only ever see their own children: the existing guardian-scoped `{child}` binding gives a 404 otherwise.
- **Local database:** never run `migrate:fresh` on the local DB (memory `feedback-local-db`); use `php artisan migrate`.
- **Staging:** stage explicit paths only (memory `feedback-staging`).
- Use Wayfinder imports, design-system components, UK English and neutral staff copy, and give every input `FieldError` and `aria-describedby`.
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `npm run check:fix && npm run types:check`. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **The reporting window and eligibility:** before the start, more than 48 hours after the end, a cancelled lesson, an absent or excused student, a student from another class, and an admin all get a 403. → Task 1 and Task 2 tests.
2. **One report per author:** a second submission updates the first, with no duplicate and no 500. → Task 2 test.
3. **Privacy of reports:** a tutor can't read a student's report and vice versa (the page only ever loads the viewer's own), and only admins see the list. → Task 2 and Task 3 tests.
4. **Repeat-lateness counting at the edges:** 3 reports inside 30 days are listed, while 2, or 3 with one at 31 days, aren't. Tutors are counted too. → Task 4 test.
5. **CSV safety:** a class name or note beginning with `=` is exported as text, and the export respects the filters. → Task 4 test.

---

### Task 1: Lesson reports schema, policy, shared attendance stats

**Files:**

- Create: `database/migrations/<ts>_create_lesson_reports_table.php`, `app/Models/LessonReport.php`, `database/factories/LessonReportFactory.php`, `app/Services/AttendanceStats.php`
- Modify: `app/Models/Lesson.php` (`lessonReports()`), `app/Policies/LessonPolicy.php` (`submitReport`), `app/Services/StudentDashboard.php` (use `AttendanceStats`)
- Test: `tests/Feature/LessonReportPolicyTest.php`, `tests/Feature/AttendanceStatsTest.php`

**Interfaces:**

- Produces:
    - **`LessonReport`:** `lesson_id`, `author_id`, `author_role: AttendeeRole`, `rating: ?int`, `went_well`, `issues`, `recommendations`, `submitted_at`; relations `lesson()` and `author()` (withTrashed); unique on `(lesson_id, author_id)`.
    - **`Lesson::lessonReports(): HasMany`**.
    - **`LessonPolicy::submitReport(User, Lesson): bool`**.
    - **`AttendanceStats::forUser(User $user, ?CarbonImmutable $now = null): array{attendance: int|null, onTime: int|null, late: int, absent: int}`**, covering the last 30 days. `attendance` is attended ÷ (attended + absent), `onTime` is present ÷ attended, and both exclude excused lessons.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest LessonReportPolicyTest --no-interaction`:

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
    $this->group = ClassGroup::factory()->create();
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->tutor = $this->lesson->tutor;
    $this->attended = fn (AttendanceStatus $status) => tap(User::factory()->student()->create(), function (User $student) use ($status) {
        $this->group->enrol($student);
        app(RecordAttendance::class)->handle($this->lesson, $student, $status, null);
    });
});

it('opens reporting from the start until 48 hours after the end', function (string $now, bool $allowed) {
    $student = ($this->attended)(AttendanceStatus::Present);
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    expect($this->tutor->can('submitReport', $this->lesson))->toBe($allowed)
        ->and($student->can('submitReport', $this->lesson))->toBe($allowed);
})->with([
    'before the start' => ['2025-10-04 08:59:00', false],
    'at the start' => ['2025-10-04 09:00:00', true],
    'just after the end' => ['2025-10-04 10:05:00', true],
    '48 hours after the end' => ['2025-10-06 10:00:00', true],
    'later than that' => ['2025-10-06 10:01:00', false],
]);

it('lets students who attended report, on time or late', function (AttendanceStatus $status, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));

    expect(($this->attended)($status)->can('submitReport', $this->lesson))->toBe($allowed);
})->with([
    'present' => [AttendanceStatus::Present, true],
    'absent' => [AttendanceStatus::Absent, false],
    'excused' => [AttendanceStatus::Excused, false],
]);

it('refuses students with no record, other tutors, guardians, admins and cancelled lessons', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));
    $unrecorded = User::factory()->student()->create();
    $this->group->enrol($unrecorded);

    foreach ([$unrecorded, User::factory()->tutor()->create(), User::factory()->guardian()->create(), User::factory()->admin()->create()] as $user) {
        expect($user->can('submitReport', $this->lesson))->toBeFalse();
    }

    $this->lesson->update(['status' => LessonStatus::Cancelled]);
    expect($this->tutor->can('submitReport', $this->lesson))->toBeFalse();
});
```

The late case is covered by `RecordAttendance` turning a late `Present` into `Late`. Add a dataset row `'late' => [AttendanceStatus::Present, true]` only if you can pass an arrival time. Leave it out here; Task 2's HTTP test covers a late student.

`php artisan make:test --pest AttendanceStatsTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AttendanceStats;
use Carbon\CarbonImmutable;

it('summarises the last 30 days, leaving out excused lessons', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-15 12:00:00', 'UTC'));
    $group = ClassGroup::factory()->create();
    $ava = User::factory()->student()->create();
    $group->enrol($ava);
    $at = fn (string $start) => Lesson::factory()->for($group)->create(['starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour()]);
    $record = app(RecordAttendance::class);

    expect(app(AttendanceStats::class)->forUser($ava))->toBe(['attendance' => null, 'onTime' => null, 'late' => 0, 'absent' => 0]);

    $record->handle($at('2025-10-01 09:00:00'), $ava, AttendanceStatus::Present, null);
    $late = $at('2025-10-04 09:00:00');
    $record->handle($late, $ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:10:00', 'UTC'));
    $record->handle($at('2025-10-08 09:00:00'), $ava, AttendanceStatus::Absent, null);
    $record->handle($at('2025-10-11 09:00:00'), $ava, AttendanceStatus::Excused, null);
    $record->handle($at('2025-09-01 09:00:00'), $ava, AttendanceStatus::Absent, null);

    expect(app(AttendanceStats::class)->forUser($ava))->toBe(['attendance' => 67, 'onTime' => 50, 'late' => 1, 'absent' => 1]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/LessonReportPolicyTest.php tests/Feature/AttendanceStatsTest.php`
Expected: FAIL. `AttendanceStats` doesn't exist, and `submitReport` returns false everywhere.

- [ ] **Step 3: Migration, model, factory**

`php artisan make:migration create_lesson_reports_table --no-interaction`:

```php
public function up(): void
{
    Schema::create('lesson_reports', function (Blueprint $table) {
        $table->id();
        $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
        $table->foreignId('author_id')->constrained('users');
        $table->string('author_role');
        $table->unsignedTinyInteger('rating')->nullable();
        $table->text('went_well')->nullable();
        $table->text('issues')->nullable();
        $table->text('recommendations')->nullable();
        $table->dateTime('submitted_at');
        $table->timestamps();

        $table->unique(['lesson_id', 'author_id']);
        $table->index(['author_role', 'submitted_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('lesson_reports');
}
```

`php artisan make:model LessonReport --factory --no-interaction`, then:

```php
<?php

namespace App\Models;

use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Database\Factories\LessonReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * End-of-class feedback from one person (the tutor or a student who attended).
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $author_id
 * @property AttendeeRole $author_role
 * @property int|null $rating
 * @property string|null $went_well
 * @property string|null $issues
 * @property string|null $recommendations
 * @property CarbonImmutable $submitted_at
 */
#[Fillable(['lesson_id', 'author_id', 'author_role', 'rating', 'went_well', 'issues', 'recommendations', 'submitted_at'])]
class LessonReport extends Model
{
    /** @use HasFactory<LessonReportFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_role' => AttendeeRole::class,
            'rating' => 'integer',
            'submitted_at' => 'immutable_datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
```

`LessonReportFactory::definition()`:

```php
return [
    'lesson_id' => Lesson::factory(),
    'author_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])->tutor_id,
    'author_role' => AttendeeRole::Tutor,
    'rating' => fake()->numberBetween(1, 5),
    'went_well' => fake()->sentence(),
    'issues' => null,
    'recommendations' => fake()->sentence(),
    'submitted_at' => now(),
];
```

(imports `App\Enums\AttendeeRole`, `App\Models\Lesson`)

In `Lesson.php`, add:

```php
/**
 * @return HasMany<LessonReport, $this>
 */
public function lessonReports(): HasMany
{
    return $this->hasMany(LessonReport::class);
}
```

- [ ] **Step 4: Policy**

Add to `LessonPolicy` (import `App\Enums\AttendanceStatus`):

```php
/**
 * The tutor, or a student who attended (on time or late), from the start until 48 hours after the end.
 * Admins read reports but never write them.
 */
public function submitReport(User $user, Lesson $lesson): bool
{
    $now = CarbonImmutable::now();

    if ($lesson->isCancelled() || $now->lessThan($lesson->starts_at) || $now->greaterThan($lesson->ends_at->addHours(48))) {
        return false;
    }

    if ($lesson->tutor_id === $user->id) {
        return true;
    }

    return $user->hasRole(Role::Student) && $lesson->attendanceRecords()
        ->where('user_id', $user->id)
        ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
        ->exists();
}
```

- [ ] **Step 5: `AttendanceStats`, and use it in `StudentDashboard`**

`php artisan make:class Services/AttendanceStats --no-interaction`:

```php
<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One person's attendance over the last 30 days. Excused lessons don't count either way.
 */
class AttendanceStats
{
    /**
     * @return array{attendance: int|null, onTime: int|null, late: int, absent: int}
     */
    public function forUser(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $statuses = AttendanceRecord::query()
            ->where('user_id', $user->id)
            ->whereHas('lesson', fn (Builder $query) => $query->whereBetween('starts_at', [$now->subDays(30)->utc(), $now->utc()]))
            ->pluck('status');

        $count = fn (AttendanceStatus $wanted): int => $statuses->filter(fn (AttendanceStatus $status): bool => $status === $wanted)->count();
        $present = $count(AttendanceStatus::Present);
        $late = $count(AttendanceStatus::Late);
        $absent = $count(AttendanceStatus::Absent);
        $attended = $present + $late;

        return [
            'attendance' => $attended + $absent === 0 ? null : (int) round($attended / ($attended + $absent) * 100),
            'onTime' => $attended === 0 ? null : (int) round($present / $attended * 100),
            'late' => $late,
            'absent' => $absent,
        ];
    }
}
```

In `StudentDashboard`:

- inject it with `public function __construct(private AttendanceStats $attendanceStats) {}`;
- delete the private `attendanceStats()` method;
- build `'stats'` with:

```php
...collect($this->attendanceStats->forUser($student, $now))->only(['attendance', 'onTime'])->all(),
```

Remove the now-unused imports. `StudentDashboardTest` must stay green.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/LessonReportPolicyTest.php tests/Feature/AttendanceStatsTest.php tests/Feature/StudentDashboardTest.php
```

Expected: PASS (5 + 3 + 1 policy tests, 1 stats test, 6 dashboard tests).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_lesson_reports_table.php app/Models/LessonReport.php database/factories/LessonReportFactory.php app/Services/AttendanceStats.php app/Models/Lesson.php app/Policies/LessonPolicy.php app/Services/StudentDashboard.php tests/Feature/LessonReportPolicyTest.php tests/Feature/AttendanceStatsTest.php
git commit -m "feat: lesson reports schema, reporting policy and shared attendance stats"
```

---

### Task 2: The end-of-class report page, with links

**Files:**

- Create: `app/Http/Controllers/LessonReportController.php`, `app/Http/Requests/LessonReportRequest.php`, `resources/js/pages/lessons/report.tsx`
- Modify: `routes/web.php`, `app/Services/StudentDashboard.php` (`lessonCard` adds `canReport`, `reported`), `app/Http/Controllers/DashboardController.php` (tutor today adds `canReport`, `reported`), `app/Http/Controllers/RegisterController.php` (`canReport`), `resources/js/types/ui.ts`, `resources/js/components/lesson-activity-card.tsx`, `resources/js/pages/dashboard/tutor.tsx`, `resources/js/pages/lessons/register.tsx`
- Test: `tests/Feature/LessonReportTest.php`

**Interfaces:**

- Consumes: `LessonPolicy::submitReport` and `LessonReport` (Task 1).
- Produces:
    - **Routes** `lessons.report.edit` (`GET /lessons/{lesson}/report`) and `lessons.report.update` (`PUT`).
    - **Report page props:**
        - `lesson`: `{ id, className, date, time }`;
        - `authorRole`: `'tutor'|'student'`;
        - `report`: `{ rating, wentWell, issues, recommendations, submittedAt } | null`;
        - `closesAt`: string ("Mon 6 Oct, 11:00").
    - **Dashboard lesson cards** gain `canReport: bool` and `reported: bool` (student and tutor). The register gains a `canReport` prop.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest LessonReportTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-04 10:30:00', 'UTC'));
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'starts_at' => CarbonImmutable::parse('2025-10-04 09:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-04 10:00:00', 'UTC'),
    ]);
    $this->tutor = $this->lesson->tutor;
    $this->ava = User::factory()->student()->create();
    $this->group->enrol($this->ava);
    app(RecordAttendance::class)->handle($this->lesson, $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:06:00', 'UTC'));
});

function reportPayload(array $overrides = []): array
{
    return ['rating' => 4, 'went_well' => 'Fractions clicked.', 'issues' => '', 'recommendations' => 'More practice on mixed numbers.', ...$overrides];
}

it('shows the same report form to the tutor and to a student who attended late', function (string $who, string $role) {
    $user = $who === 'tutor' ? $this->tutor : $this->ava;

    $this->actingAs($user)->get(route('lessons.report.edit', $this->lesson))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lessons/report')
            ->where('lesson.className', 'Year 5 Maths')
            ->where('lesson.date', 'Saturday 4 October')
            ->where('authorRole', $role)
            ->where('report', null)
            ->where('closesAt', 'Mon 6 Oct, 11:00'));
})->with([['tutor', 'tutor'], ['student', 'student']]);

it('saves a report and updates it on a second submission', function () {
    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload())
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success');

    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload(['rating' => 5, 'issues' => 'Room was cold.']));

    $report = LessonReport::sole();
    expect($report->author_id)->toBe($this->ava->id)
        ->and($report->author_role)->toBe(AttendeeRole::Student)
        ->and($report->rating)->toBe(5)
        ->and($report->issues)->toBe('Room was cold.');

    $this->actingAs($this->ava)->get(route('lessons.report.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('report.rating', 5)->where('report.wentWell', 'Fractions clicked.'));
});

it('only ever shows the viewer their own report', function () {
    LessonReport::factory()->for($this->lesson)->create(['author_id' => $this->tutor->id, 'went_well' => 'Tutor view']);

    $this->actingAs($this->ava)->get(route('lessons.report.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('report', null));
});

it('needs at least something written and a rating from 1 to 5', function () {
    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), ['rating' => '', 'went_well' => '', 'issues' => '', 'recommendations' => ''])
        ->assertSessionHasErrors('went_well');

    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload(['rating' => 6]))
        ->assertSessionHasErrors('rating');

    expect(LessonReport::count())->toBe(0);
});

it('refuses people who may not report on this lesson', function (string $who) {
    $user = match ($who) {
        'absent student' => tap(User::factory()->student()->create(), function (User $student) {
            $this->group->enrol($student);
            app(RecordAttendance::class)->handle($this->lesson, $student, AttendanceStatus::Absent, null);
        }),
        'admin' => User::factory()->admin()->create(),
        'other tutor' => User::factory()->tutor()->create(),
    };

    $this->actingAs($user)->get(route('lessons.report.edit', $this->lesson))->assertForbidden();
    $this->actingAs($user)->put(route('lessons.report.update', $this->lesson), reportPayload())->assertForbidden();
})->with(['absent student', 'admin', 'other tutor']);

it('closes 48 hours after the lesson ends', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-06 10:01:00', 'UTC'));

    $this->actingAs($this->ava)->put(route('lessons.report.update', $this->lesson), reportPayload())->assertForbidden();
});

it('offers the report on the student dashboard and the tutor Today screen', function () {
    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('today.0.canReport', true)->where('today.0.reported', false));

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('today.0.canReport', true));

    $this->actingAs($this->tutor)->get(route('lessons.register.show', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('canReport', true));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/LessonReportTest.php`
Expected: FAIL with `Route [lessons.report.edit] not defined`.

- [ ] **Step 3: Routes, request, controller**

In the `auth` + `verified` group in `routes/web.php` (add `use App\Http\Controllers\LessonReportController;`):

```php
Route::get('/lessons/{lesson}/report', [LessonReportController::class, 'edit'])->name('lessons.report.edit');
Route::put('/lessons/{lesson}/report', [LessonReportController::class, 'update'])->name('lessons.report.update');
```

`php artisan make:request LessonReportRequest --no-interaction`:

```php
<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LessonReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('submitReport', $this->route('lesson'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'went_well' => ['nullable', 'string', 'max:2000'],
            'issues' => ['nullable', 'string', 'max:2000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('rating') && ! $this->filled('went_well') && ! $this->filled('issues') && ! $this->filled('recommendations')) {
                    $validator->errors()->add('went_well', 'Add a rating or write at least one comment.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['rating.between' => 'Choose a rating from 1 to 5.'];
    }
}
```

`php artisan make:controller LessonReportController --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\AttendeeRole;
use App\Http\Requests\LessonReportRequest;
use App\Models\Lesson;
use App\Models\LessonReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonReportController extends Controller
{
    public function edit(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('submitReport', $lesson);

        $report = $lesson->lessonReports()->where('author_id', $request->user()->id)->first();

        return Inertia::render('lessons/report', [
            'lesson' => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'date' => $lesson->localStart()->format('l j F'),
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
            ],
            'authorRole' => $this->roleFor($request, $lesson)->value,
            'report' => $report === null ? null : [
                'rating' => $report->rating,
                'wentWell' => $report->went_well,
                'issues' => $report->issues,
                'recommendations' => $report->recommendations,
                'submittedAt' => $report->submitted_at->setTimezone(config('academy.timezone'))->format('D j M, H:i'),
            ],
            'closesAt' => $lesson->ends_at->addHours(48)->setTimezone(config('academy.timezone'))->format('D j M, H:i'),
        ]);
    }

    public function update(LessonReportRequest $request, Lesson $lesson): RedirectResponse
    {
        LessonReport::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'author_id' => $request->user()->id],
            [
                'author_role' => $this->roleFor($request, $lesson),
                'rating' => $request->validated('rating'),
                'went_well' => $request->validated('went_well'),
                'issues' => $request->validated('issues'),
                'recommendations' => $request->validated('recommendations'),
                'submitted_at' => CarbonImmutable::now(),
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks — your report has been saved.']);

        return to_route('dashboard');
    }

    private function roleFor(Request $request, Lesson $lesson): AttendeeRole
    {
        return $lesson->tutor_id === $request->user()->id ? AttendeeRole::Tutor : AttendeeRole::Student;
    }
}
```

- [ ] **Step 4: Report links in the data**

- **`StudentDashboard`:** extend the eager load to `'lessonReports' => fn (HasMany $query) => $query->where('author_id', $student->id)`. Pass `$student` into `lessonCard` and add:

```php
'canReport' => $student->can('submitReport', $lesson),
'reported' => $lesson->lessonReports->isNotEmpty(),
```

`submitReport` runs one `exists()` query per lesson. That's acceptable for a handful of lessons a week. Note it in the ledger.

- **`DashboardController::tutorToday`:** eager-load `'lessonReports' => fn (HasMany $query) => $query->where('author_id', $tutor->id)` and add `'canReport' => $tutor->can('submitReport', $lesson)` and `'reported' => $lesson->lessonReports->isNotEmpty()` to each today row.
- **`RegisterController::show`:** add `'canReport' => $request->user()->can('submitReport', $lesson)`.

- [ ] **Step 5: UI**

`resources/js/types/ui.ts`: add `canReport: boolean; reported: boolean;` to `DashboardLesson`.

`lesson-activity-card.tsx`: add a footer link when `lesson.canReport`:

```tsx
{
    lesson.canReport && (
        <Link
            href={editReport(lesson.id)}
            className="inline-flex h-10 items-center justify-center rounded-md border border-input bg-surface text-sm font-medium text-primary hover:bg-surface-muted"
        >
            {lesson.reported ? 'Edit your report' : 'Write a report'}
            <span className="sr-only"> for {lesson.className}</span>
        </Link>
    );
}
```

(imports `Link` from `@inertiajs/react` and `{ edit as editReport }` from `@/routes/lessons/report`)

`dashboard/tutor.tsx`: add `canReport` and `reported` to `TodayLesson`, plus the same link inside each card's `CardContent` when `canReport`.

`lessons/register.tsx`: accept a `canReport` prop and render a "Write end-of-class report" link above the form when true.

`resources/js/pages/lessons/report.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/lessons/report';

type ReportProps = {
    lesson: { id: number; className: string; date: string; time: string };
    authorRole: 'tutor' | 'student';
    report: {
        rating: number | null;
        wentWell: string | null;
        issues: string | null;
        recommendations: string | null;
        submittedAt: string;
    } | null;
    closesAt: string;
};

const textarea =
    'min-h-24 w-full rounded-md border border-input bg-surface px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground aria-invalid:border-danger';

export default function LessonReport({
    lesson,
    authorRole,
    report,
    closesAt,
}: ReportProps) {
    const prompts =
        authorRole === 'tutor'
            ? {
                  wentWell: 'What went well in this lesson?',
                  issues: 'Any issues or concerns?',
                  recommendations: 'Recommendations for next time',
              }
            : {
                  wentWell: 'What did you enjoy or understand well?',
                  issues: 'Was anything difficult or a problem?',
                  recommendations: 'What would help you next time?',
              };

    return (
        <AppLayout
            title="End-of-class report"
            subtitle={`${lesson.className} · ${lesson.date}, ${lesson.time}`}
        >
            <Card className="max-w-2xl">
                <CardHeader>
                    <CardTitle>
                        {report ? 'Edit your report' : 'How did it go?'}
                    </CardTitle>
                    <CardDescription>
                        {report ? `Last saved ${report.submittedAt}. ` : ''}You
                        can change this until {closesAt}. Only you and the
                        academy team can read it.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        {...update.form(lesson.id)}
                        className="flex flex-col gap-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <fieldset
                                    className="flex flex-col gap-2"
                                    aria-describedby="rating-error"
                                >
                                    <legend className="text-sm font-medium">
                                        Overall rating (optional)
                                    </legend>
                                    <div className="flex flex-wrap gap-2">
                                        {[1, 2, 3, 4, 5].map((value) => (
                                            <label
                                                key={value}
                                                className="inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-input text-sm font-semibold has-checked:border-primary has-checked:bg-royal-50 dark:has-checked:bg-royal-900/40"
                                            >
                                                <input
                                                    type="radio"
                                                    name="rating"
                                                    value={value}
                                                    defaultChecked={
                                                        report?.rating === value
                                                    }
                                                    className="sr-only"
                                                />
                                                {value}
                                            </label>
                                        ))}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        1 = poor, 5 = excellent
                                    </p>
                                    <FieldError
                                        id="rating-error"
                                        message={errors.rating}
                                    />
                                </fieldset>
                                {(
                                    [
                                        [
                                            'went_well',
                                            prompts.wentWell,
                                            report?.wentWell,
                                        ],
                                        [
                                            'issues',
                                            prompts.issues,
                                            report?.issues,
                                        ],
                                        [
                                            'recommendations',
                                            prompts.recommendations,
                                            report?.recommendations,
                                        ],
                                    ] as const
                                ).map(([name, label, value]) => (
                                    <div
                                        key={name}
                                        className="flex flex-col gap-1.5"
                                    >
                                        <Label htmlFor={name}>{label}</Label>
                                        <textarea
                                            id={name}
                                            name={name}
                                            defaultValue={value ?? ''}
                                            maxLength={2000}
                                            className={textarea}
                                            aria-invalid={!!errors[name]}
                                            aria-describedby={`${name}-error`}
                                        />
                                        <FieldError
                                            id={`${name}-error`}
                                            message={errors[name]}
                                        />
                                    </div>
                                ))}
                                <div>
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={processing}
                                    >
                                        {processing ? 'Saving…' : 'Save report'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/LessonReportTest.php tests/Feature/StudentDashboardTest.php tests/Feature/TutorCheckInTest.php tests/Feature/RegisterTest.php
```

Expected: PASS (LessonReportTest: 2 + 1 + 1 + 1 + 3 + 1 + 1 = 10).

- [ ] **Step 7: Commit**

`npm run check:fix && npm run types:check`, then commit the touched files explicitly with the message `feat: end-of-class reports for tutors and students`.

---

### Task 3: Admin — lesson reports list

**Files:**

- Create: `app/Http/Controllers/Admin/LessonReportController.php`, `resources/js/pages/admin/lesson-reports/index.tsx`
- Modify: `routes/web.php`, `resources/js/components/app-sidebar.tsx` (the admin "Reports" item, icon `book`)
- Test: `tests/Feature/Admin/LessonReportListTest.php`

**Interfaces:**

- Produces:
    - **Route** `admin.lesson-reports.index` (`GET /admin/lesson-reports`), with query `class` (class group id), `role` (`tutor|student`), `from` and `to` (`Y-m-d`, academy dates).
    - **Props:**
        - `reports: Paginated<{ id, lessonDate, className, author, authorRole, rating, wentWell, issues, recommendations }>`;
        - `filters`;
        - `classes: Option[]`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Admin/LessonReportListTest --no-interaction`:

```php
<?php

use App\Enums\AttendeeRole;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->admin = User::factory()->admin()->create();
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->other = ClassGroup::factory()->create(['name' => 'Year 7 Maths']);
    $this->lessonOn = fn (string $date, ClassGroup $group) => Lesson::factory()->for($group)->create([
        'starts_at' => CarbonImmutable::parse("{$date} 09:00:00", 'UTC'),
        'ends_at' => CarbonImmutable::parse("{$date} 10:00:00", 'UTC'),
    ]);
});

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('admin.lesson-reports.index'))->assertForbidden();
})->with(['tutor', 'student', 'guardian']);

it('lists reports from tutors and students, newest lesson first', function () {
    $older = ($this->lessonOn)('2025-10-01', $this->group);
    $newer = ($this->lessonOn)('2025-10-08', $this->group);
    LessonReport::factory()->for($older)->create(['went_well' => 'Older']);
    $student = User::factory()->student()->create(['name' => 'Ava']);
    LessonReport::factory()->for($newer)->create(['author_id' => $student->id, 'author_role' => AttendeeRole::Student, 'rating' => 5, 'went_well' => 'Newer']);

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/lesson-reports/index')
            ->has('reports.data', 2)
            ->where('reports.data.0.wentWell', 'Newer')
            ->where('reports.data.0.author', 'Ava')
            ->where('reports.data.0.authorRole', 'student')
            ->where('reports.data.0.rating', 5)
            ->where('reports.data.0.lessonDate', 'Wed 8 Oct 2025')
            ->where('reports.data.0.className', 'Year 5 Maths')
            ->has('classes', 2));
});

it('filters by class, author role and date range', function () {
    LessonReport::factory()->for(($this->lessonOn)('2025-10-01', $this->group))->create();
    LessonReport::factory()->for(($this->lessonOn)('2025-10-08', $this->other))->create();
    $student = User::factory()->student()->create();
    LessonReport::factory()->for(($this->lessonOn)('2025-10-15', $this->group))->create(['author_id' => $student->id, 'author_role' => AttendeeRole::Student]);

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['class' => $this->other->id]))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.className', 'Year 7 Maths'));

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['role' => 'student']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1));

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['from' => '2025-10-05', 'to' => '2025-10-10']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.className', 'Year 7 Maths'));
});

it('ignores filters it does not understand', function () {
    LessonReport::factory()->for(($this->lessonOn)('2025-10-01', $this->group))->create();

    $this->actingAs($this->admin)->get(route('admin.lesson-reports.index', ['role' => 'admin', 'from' => 'yesterday', 'class' => 'abc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/LessonReportListTest.php`
Expected: FAIL with `Route [admin.lesson-reports.index] not defined`.

- [ ] **Step 3: Controller and route**

Route, in the admin group (with `use App\Http\Controllers\Admin\LessonReportController as AdminLessonReportController;`):

```php
Route::get('lesson-reports', [AdminLessonReportController::class, 'index'])->name('lesson-reports.index');
```

`php artisan make:controller Admin/LessonReportController --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendeeRole;
use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\LessonReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LessonReportController extends Controller
{
    public function index(Request $request): Response
    {
        $timezone = config('academy.timezone');
        $classId = filter_var($request->query('class'), FILTER_VALIDATE_INT) ?: null;
        $role = AttendeeRole::tryFrom((string) $request->query('role'));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $reports = LessonReport::query()
            ->select('lesson_reports.*')
            ->join('lessons', 'lessons.id', '=', 'lesson_reports.lesson_id')
            ->with(['lesson.classGroup', 'author'])
            ->when($classId, fn (Builder $query) => $query->where('lessons.class_group_id', $classId))
            ->when($role, fn (Builder $query) => $query->where('lesson_reports.author_role', $role))
            ->when($from, fn (Builder $query) => $query->where('lessons.starts_at', '>=', $from->startOfDay()->utc()))
            ->when($to, fn (Builder $query) => $query->where('lessons.starts_at', '<=', $to->endOfDay()->utc()))
            ->orderByDesc('lessons.starts_at')
            ->orderByDesc('lesson_reports.id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LessonReport $report): array => [
                'id' => $report->id,
                'lessonDate' => $report->lesson->localStart()->format('D j M Y'),
                'className' => $report->lesson->classGroup->name,
                'author' => $report->author->name,
                'authorRole' => $report->author_role->value,
                'rating' => $report->rating,
                'wentWell' => $report->went_well,
                'issues' => $report->issues,
                'recommendations' => $report->recommendations,
            ]);

        return Inertia::render('admin/lesson-reports/index', [
            'reports' => $reports,
            'filters' => [
                'class' => $classId,
                'role' => $role?->value,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'classes' => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])->all(),
        ]);
    }

    /**
     * Read a Y-m-d date in the academy timezone, or null if it isn't one.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('academy.timezone'));

        return $date === false ? null : $date;
    }
}
```

`$timezone` is unused, so remove it (Pint and Larastan will flag it). `!Y-m-d` resets the time to midnight in the academy timezone.

- [ ] **Step 4: Page and nav**

`resources/js/pages/admin/lesson-reports/index.tsx`:

- **Filter bar:** a GET `<Form {...index.form()}>` with a Class select, an Author select (All / Tutors / Students), and From and To `type="date"` inputs.
- **List:** cards, one per report. The header shows the date, class, author and a role badge (`brand` for a tutor, `neutral` for a student), plus "Rating 4/5" when set. The body has three labelled sections (What went well / Issues / Recommendations), each shown only when filled.
- **Empty state:** "No reports match these filters."
- **Pagination:** Previous/Next, as on the users page.

In the sidebar's `navItems.admin`, add `{ label: 'Reports', icon: 'book', href: adminLessonReportsIndex }`.

- [ ] **Step 5: Run the tests to verify they pass, then commit**

`php artisan wayfinder:generate --with-form`, then `php artisan test --compact tests/Feature/Admin/LessonReportListTest.php`. Expected: PASS (3 + 1 + 1 + 1 = 6). Then run checks, and commit with `feat: admin lesson reports list`.

---

### Task 4: Admin — lateness reports, repeat lateness, follow-up, CSV export

**Files:**

- Create: `app/Http/Controllers/Admin/LatenessReportController.php`, `app/Http/Requests/Admin/ReviewLatenessReportRequest.php`, `app/Services/LatenessReportQuery.php`, `resources/js/pages/admin/lateness/index.tsx`
- Modify: `routes/web.php`, `resources/js/components/app-sidebar.tsx` (the admin "Lateness" item, icon `clock`)
- Test: `tests/Feature/Admin/LatenessReportsTest.php`

**Interfaces:**

- Produces:
    - **Routes:**
        - `admin.lateness.index` (`GET /admin/lateness`), with query `role` (`student|tutor`), `class`, `from`, `to`, `search`;
        - `admin.lateness.update` (`PUT /admin/lateness/{latenessReport}`), with body `follow_up_notes` and `reviewed` (bool);
        - `admin.lateness.export` (`GET /admin/lateness/export`), which takes the same query.
    - **`LatenessReportQuery::filtered(array $filters): Builder`**, shared by the list and the export.
    - **Index props:**
        - `reports: Paginated<Row>`, `filters`, `classes`;
        - `repeat: { userId, name, role, count, lastDate }[]`.

        A `Row` is `{ id, date, name, role, className, scheduled, arrived, minutesLate, followUpNotes, reviewedBy, reviewedAt }`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Admin/LatenessReportsTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2025-10-31 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->tutor = $this->group->tutor;
    $this->tutor->update(['name' => 'Tunde Tutor']);
    $this->ava = User::factory()->student()->create(['name' => 'Ava']);
    $this->group->enrol($this->ava);
    $this->lateOn = function (string $date, User $who, int $minutes = 7, ?ClassGroup $group = null) {
        $group ??= $this->group;
        $lesson = Lesson::factory()->for($group)->create([
            'tutor_id' => $group->tutor_id,
            'starts_at' => CarbonImmutable::parse("{$date} 09:00:00", 'UTC'),
            'ends_at' => CarbonImmutable::parse("{$date} 10:00:00", 'UTC'),
        ]);

        return app(RecordAttendance::class)->handle($lesson, $who, AttendanceStatus::Present, null, CarbonImmutable::parse("{$date} 09:00:00", 'UTC')->addMinutes($minutes))->latenessReport;
    };
});

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('admin.lateness.index'))->assertForbidden();
})->with(['tutor', 'student', 'guardian']);

it('lists lateness for students and tutors in academy time, newest first', function () {
    ($this->lateOn)('2025-10-20', $this->ava, 7);
    ($this->lateOn)('2025-10-27', $this->tutor, 12);

    $this->actingAs($this->admin)->get(route('admin.lateness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/lateness/index')
            ->has('reports.data', 2)
            ->where('reports.data.0.role', 'tutor')
            ->where('reports.data.0.minutesLate', 12)
            ->where('reports.data.1.name', 'Ava')
            ->where('reports.data.1.date', 'Mon 20 Oct 2025')
            ->where('reports.data.1.scheduled', '10:00')
            ->where('reports.data.1.arrived', '10:07')
            ->where('reports.data.1.className', 'Year 5 Maths'));
});

it('filters by role, class, date range and name', function () {
    $other = ClassGroup::factory()->create(['name' => 'Year 7 Maths']);
    $bola = User::factory()->student()->create(['name' => 'Bola']);
    $other->enrol($bola);
    ($this->lateOn)('2025-10-06', $this->ava);
    ($this->lateOn)('2025-10-13', $this->tutor);
    ($this->lateOn)('2025-10-20', $bola, 5, $other);

    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['role' => 'tutor']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.role', 'tutor'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['class' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.name', 'Bola'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['from' => '2025-10-10', 'to' => '2025-10-15']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.role', 'tutor'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['search' => 'av']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.name', 'Ava'));
});

it('flags repeat lateness: 3 or more in the last 30 days, students and tutors alike', function () {
    foreach (['2025-10-06', '2025-10-13', '2025-10-20'] as $date) {
        ($this->lateOn)($date, $this->ava);
    }
    foreach (['2025-09-30', '2025-10-13', '2025-10-20'] as $date) {  // 30 Sept is 31 days ago
        ($this->lateOn)($date, $this->tutor);
    }
    $tutor2 = User::factory()->tutor()->create(['name' => 'Tola']);
    $group2 = ClassGroup::factory()->for($tutor2, 'tutor')->create();
    foreach (['2025-10-14', '2025-10-21', '2025-10-28'] as $date) {
        ($this->lateOn)($date, $tutor2, 3, $group2);
    }

    $this->actingAs($this->admin)->get(route('admin.lateness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('repeat', 2)
            ->where('repeat.0.name', 'Tola')
            ->where('repeat.0.role', 'tutor')
            ->where('repeat.0.count', 3)
            ->where('repeat.0.lastDate', 'Tue 28 Oct')
            ->where('repeat.1.name', 'Ava'));
});

it('records a follow-up and marks the report reviewed', function () {
    $report = ($this->lateOn)('2025-10-20', $this->ava);

    $this->actingAs($this->admin)->put(route('admin.lateness.update', $report), ['follow_up_notes' => 'Spoke to parent.', 'reviewed' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $report->refresh();
    expect($report->follow_up_notes)->toBe('Spoke to parent.')
        ->and($report->reviewed_by_id)->toBe($this->admin->id)
        ->and($report->reviewed_at)->not->toBeNull();

    $this->actingAs($this->admin)->put(route('admin.lateness.update', $report), ['follow_up_notes' => 'Spoke to parent.', 'reviewed' => false]);
    expect($report->fresh()->reviewed_at)->toBeNull();
});

it('exports the filtered list as CSV, neutralising formulas', function () {
    $evil = ClassGroup::factory()->create(['name' => '=HYPERLINK("http://x","click")']);
    $bola = User::factory()->student()->create(['name' => 'Bola']);
    $evil->enrol($bola);
    ($this->lateOn)('2025-10-20', $bola, 9, $evil);
    ($this->lateOn)('2025-10-21', $this->tutor);

    $response = $this->actingAs($this->admin)->get(route('admin.lateness.export', ['role' => 'student']));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('Date,Name,Role,Class')
        ->and($lines[1])->toContain('Bola')
        ->and($lines[1])->toContain("'=HYPERLINK")
        ->and($lines[1])->toContain(',9,');
});

it('keeps exporting admin-only', function () {
    $this->actingAs(User::factory()->tutor()->create())->get(route('admin.lateness.export'))->assertForbidden();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/LatenessReportsTest.php`
Expected: FAIL with `Route [admin.lateness.index] not defined`.

- [ ] **Step 3: Shared query**

`php artisan make:class Services/LatenessReportQuery --no-interaction`:

```php
<?php

namespace App\Services;

use App\Enums\AttendeeRole;
use App\Models\LatenessReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Filters for the lateness list and its CSV export. Unknown or malformed filters are ignored.
 */
class LatenessReportQuery
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{role: AttendeeRole|null, class: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string}
     */
    public function filters(array $input): array
    {
        return [
            'role' => AttendeeRole::tryFrom((string) ($input['role'] ?? '')),
            'class' => filter_var($input['class'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'from' => $this->date($input['from'] ?? null),
            'to' => $this->date($input['to'] ?? null),
            'search' => Str::lower(trim((string) ($input['search'] ?? ''))),
        ];
    }

    /**
     * @param  array{role: AttendeeRole|null, class: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string}  $filters
     * @return Builder<LatenessReport>
     */
    public function filtered(array $filters): Builder
    {
        return LatenessReport::query()
            ->with(['user', 'reviewedBy'])
            ->when($filters['role'], fn (Builder $query, AttendeeRole $role) => $query->where('attendee_role', $role))
            ->when($filters['class'], fn (Builder $query, int $class) => $query->whereHas('lesson', fn (Builder $lesson) => $lesson->where('class_group_id', $class)))
            ->when($filters['from'], fn (Builder $query, CarbonImmutable $from) => $query->where('lesson_date', '>=', $from->toDateString()))
            ->when($filters['to'], fn (Builder $query, CarbonImmutable $to) => $query->where('lesson_date', '<=', $to->toDateString()))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user->withTrashed()->whereRaw('LOWER(name) LIKE ?', ['%'.addcslashes($filters['search'], '%_\\').'%'])))
            ->orderByDesc('lesson_date')
            ->orderByDesc('scheduled_start_at')
            ->orderByDesc('id');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('academy.timezone'));

        return $date === false ? null : $date;
    }
}
```

`whereHas('user', …)` with the `User` soft-delete scope: call `->withTrashed()` inside the closure, as written, so lateness by deactivated people stays searchable. Escaping `%` and `_` closes the 2b LIKE-wildcard minor for this search. The SQLite LIKE escape char may need `ESCAPE '\'`: if a `%` search misbehaves in tests, append ` ESCAPE '\\'` to the raw clause and ledger it.

- [ ] **Step 4: Controller, request, routes**

Routes, in the admin group (with `use App\Http\Controllers\Admin\LatenessReportController;`). Declare the export **before** `{latenessReport}`:

```php
Route::get('lateness/export', [LatenessReportController::class, 'export'])->name('lateness.export');
Route::get('lateness', [LatenessReportController::class, 'index'])->name('lateness.index');
Route::put('lateness/{latenessReport}', [LatenessReportController::class, 'update'])->name('lateness.update');
```

`php artisan make:request Admin/ReviewLatenessReportRequest --no-interaction`, with `authorize(): true` (the route middleware already restricts it) and:

```php
public function rules(): array
{
    return [
        'follow_up_notes' => ['nullable', 'string', 'max:2000'],
        'reviewed' => ['required', 'boolean'],
    ];
}
```

`php artisan make:controller Admin/LatenessReportController --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewLatenessReportRequest;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\User;
use App\Services\LatenessReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LatenessReportController extends Controller
{
    public function index(Request $request, LatenessReportQuery $query): Response
    {
        $filters = $query->filters($request->query());

        return Inertia::render('admin/lateness/index', [
            'reports' => $query->filtered($filters)->paginate(25)->withQueryString()->through(fn (LatenessReport $report): array => $this->row($report)),
            'filters' => [
                'role' => $filters['role']?->value,
                'class' => $filters['class'],
                'from' => $filters['from']?->toDateString(),
                'to' => $filters['to']?->toDateString(),
                'search' => $filters['search'],
            ],
            'classes' => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])->all(),
            'repeat' => $this->repeatLateness(),
        ]);
    }

    public function update(ReviewLatenessReportRequest $request, LatenessReport $latenessReport): RedirectResponse
    {
        $reviewed = $request->boolean('reviewed');

        $latenessReport->update([
            'follow_up_notes' => $request->validated('follow_up_notes'),
            'reviewed_by_id' => $reviewed ? $request->user()->id : null,
            'reviewed_at' => $reviewed ? ($latenessReport->reviewed_at ?? CarbonImmutable::now()) : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Follow-up saved.']);

        return back();
    }

    public function export(Request $request, LatenessReportQuery $query): StreamedResponse
    {
        $filters = $query->filters($request->query());
        $timezone = config('academy.timezone');

        return response()->streamDownload(function () use ($query, $filters, $timezone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Name', 'Role', 'Class', "Scheduled start ({$timezone})", "Arrived ({$timezone})", 'Minutes late', 'Reviewed by', 'Reviewed at', 'Follow-up notes'], escape: '');

            $query->filtered($filters)->lazy(500)->each(function (LatenessReport $report) use ($out, $timezone): void {
                fputcsv($out, array_map($this->safeCell(...), [
                    $report->lesson_date->toDateString(),
                    $report->user->name,
                    $report->attendee_role->value,
                    $report->class_name,
                    $report->scheduled_start_at->setTimezone($timezone)->format('H:i'),
                    $report->arrived_at->setTimezone($timezone)->format('H:i'),
                    (string) $report->minutes_late,
                    $report->reviewedBy?->name ?? '',
                    $report->reviewed_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? '',
                    $report->follow_up_notes ?? '',
                ]), escape: '');
            });

            fclose($out);
        }, 'lateness-reports-'.CarbonImmutable::now($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LatenessReport $report): array
    {
        $timezone = config('academy.timezone');

        return [
            'id' => $report->id,
            'date' => $report->lesson_date->format('D j M Y'),
            'name' => $report->user->name,
            'role' => $report->attendee_role->value,
            'className' => $report->class_name,
            'scheduled' => $report->scheduled_start_at->setTimezone($timezone)->format('H:i'),
            'arrived' => $report->arrived_at->setTimezone($timezone)->format('H:i'),
            'minutesLate' => $report->minutes_late,
            'followUpNotes' => $report->follow_up_notes,
            'reviewedBy' => $report->reviewedBy?->name,
            'reviewedAt' => $report->reviewed_at?->setTimezone($timezone)->format('j M, H:i'),
        ];
    }

    /**
     * People late 3 or more times in the last 30 days (academy dates), most frequent first.
     *
     * @return array<int, array{userId: int, name: string, role: string, count: int, lastDate: string}>
     */
    private function repeatLateness(): array
    {
        $since = CarbonImmutable::now(config('academy.timezone'))->subDays(30)->toDateString();

        $rows = LatenessReport::query()
            ->select('user_id', 'attendee_role', DB::raw('COUNT(*) as late_count'), DB::raw('MAX(lesson_date) as last_date'))
            ->where('lesson_date', '>=', $since)
            ->groupBy('user_id', 'attendee_role')
            ->having('late_count', '>=', 3)
            ->orderByDesc('late_count')
            ->orderByDesc('last_date')
            ->get();

        $names = User::withTrashed()->whereKey($rows->pluck('user_id'))->pluck('name', 'id');

        return $rows->map(fn (LatenessReport $row): array => [
            'userId' => (int) $row->user_id,
            'name' => $names[$row->user_id] ?? 'Unknown',
            'role' => $row->attendee_role->value,
            'count' => (int) $row->late_count,
            'lastDate' => CarbonImmutable::parse($row->last_date)->format('D j M'),
        ])->values()->all();
    }

    /**
     * Stop spreadsheet apps from treating a cell as a formula.
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
```

Notes:

- **The repeat test:** Ava's last late date is 20 Oct and Tola's is 28 Oct, both with a count of 3, so the `orderByDesc('last_date')` tie-break puts Tola first. The tutor with a report on 30 Sept has only 2 inside the window (31 Oct minus 30 days is 1 Oct, and 30 Sept is earlier), so they aren't listed.
- **`having('late_count', …)`** uses the alias. If SQLite or MySQL complain, use `havingRaw('COUNT(*) >= 3')` and ledger it.
- **`minutes_late`** is cast to a string before `safeCell`. Negative numbers can't occur.
- **The export test's** `',9,'` relies on fputcsv leaving bare numbers unquoted.

- [ ] **Step 5: Page and nav**

`resources/js/pages/admin/lateness/index.tsx`:

- **Repeat lateness card,** shown at the top only when `repeat.length > 0`. Its heading is "Repeat lateness · last 30 days", with one row per person: name, a role badge, "{count} times", and "last {lastDate}". Each row's name is a button that sets the search filter to that name (`router.get(index.url({ query: { search: name } }))`).
- **Filter bar:** a GET `<Form {...index.form()}>` with Search (name), Role (All / Students / Tutors), Class, and From and To `type="date"` inputs. Beside it, an **Export CSV** link: an `<a href={exportCsv.url({ query: currentFilters })} download>`, a plain anchor, not an Inertia Link.
- **Table** with these columns:
    - date;
    - name, with a role badge;
    - class;
    - scheduled time;
    - arrived time, plus a `late` badge showing the minutes;
    - follow-up: if reviewed, a "Reviewed by X on Y" badge and the notes; otherwise "Not reviewed".

    Each row also has a "Follow up" toggle that reveals an inline `<Form {...update.form(row.id)}>`. The form has a textarea `follow_up_notes` and a checkbox `reviewed` (hidden input `0`, checkbox `1`), and saves with `preserveScroll`.

- **Empty state:** "No lateness recorded for these filters."
- **Pagination:** as on the users page.

Sidebar `navItems.admin`: add `{ label: 'Lateness', icon: 'clock', href: adminLatenessIndex }`.

- [ ] **Step 6: Run the tests to verify they pass, then commit**

`php artisan wayfinder:generate --with-form`, then `php artisan test --compact tests/Feature/Admin/LatenessReportsTest.php`. Expected: PASS (3 + 1 + 1 + 1 + 1 + 1 + 1 = 9). Then run checks, and commit with `feat: admin lateness reports with follow-up and CSV export`.

---

### Task 5: Parents see their children's attendance

**Files:**

- Create: `app/Http/Controllers/ChildAttendanceController.php`, `resources/js/pages/children/attendance.tsx`
- Modify: `routes/web.php`, `app/Http/Controllers/ChildController.php` (`index` adds `attendance` and `late`), `resources/js/pages/children/index.tsx`
- Test: `tests/Feature/ChildAttendanceTest.php`

**Interfaces:**

- Consumes: `AttendanceStats` (Task 1) and the guardian-scoped `{child}` binding.
- Produces:
    - **Route** `children.attendance` (`GET /children/{child}/attendance`, `role:guardian`).
    - **Props:**
        - `child: { id, name, yearGroup }`;
        - `stats` (the `AttendanceStats` shape);
        - `records: { id, date, className, time, status, minutesLate }[]`, the latest 30 records, newest first.
    - The children index rows gain `attendance: int|null` and `late: int`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest ChildAttendanceTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2025-10-15 12:00:00', 'UTC'));
    $this->parent = User::factory()->guardian()->create();
    $this->ava = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava']);
    $this->parent->linkChild($this->ava);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->group->enrol($this->ava);
    $record = app(RecordAttendance::class);
    $lesson = fn (string $start) => Lesson::factory()->for($this->group)->create(['starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour()]);
    $record->handle($lesson('2025-10-04 09:00:00'), $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:08:00', 'UTC'));
    $record->handle($lesson('2025-10-11 09:00:00'), $this->ava, AttendanceStatus::Absent, null);
});

it('shows a parent their child\'s attendance and lateness, newest first', function () {
    $this->actingAs($this->parent)->get(route('children.attendance', $this->ava))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/attendance')
            ->where('child.name', 'Ava')
            ->where('child.yearGroup', 'Year 5')
            ->where('stats.attendance', 50)
            ->where('stats.onTime', 0)
            ->where('stats.late', 1)
            ->where('stats.absent', 1)
            ->has('records', 2)
            ->where('records.0.date', 'Sat 11 Oct')
            ->where('records.0.status', 'absent')
            ->where('records.1.status', 'late')
            ->where('records.1.minutesLate', 8)
            ->where('records.1.time', '10:00–11:00')
            ->where('records.1.className', 'Year 5 Maths'));
});

it('adds attendance to the children list', function () {
    $this->actingAs($this->parent)->get(route('children.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.0.attendance', 50)->where('children.0.late', 1));
});

it('hides other families\' children', function () {
    $this->actingAs(User::factory()->guardian()->create())
        ->get(route('children.attendance', $this->ava))
        ->assertNotFound();
});

it('is only for guardians', function () {
    $this->actingAs(User::factory()->tutor()->create())
        ->get(route('children.attendance', $this->ava))
        ->assertNotFound();
});
```

The last test expects 404, not 403. The scoped binding runs before `role:guardian` (see the 2b plan note), so a tutor's empty `children()` gives a 404. That's acceptable, and it's asserted here so the behaviour is deliberate.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/ChildAttendanceTest.php`
Expected: FAIL with `Route [children.attendance] not defined`.

- [ ] **Step 3: Controller, route, index stats**

Route, in the guardian group:

```php
Route::get('children/{child}/attendance', ChildAttendanceController::class)->name('children.attendance');
```

`php artisan make:controller ChildAttendanceController --invokable --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceStats;
use Inertia\Inertia;
use Inertia\Response;

class ChildAttendanceController extends Controller
{
    /**
     * A guardian's view of one child's attendance and punctuality.
     */
    public function __invoke(User $child, AttendanceStats $stats): Response
    {
        $records = AttendanceRecord::query()
            ->where('user_id', $child->id)
            ->join('lessons', 'lessons.id', '=', 'attendance_records.lesson_id')
            ->select('attendance_records.*')
            ->with('lesson.classGroup')
            ->orderByDesc('lessons.starts_at')
            ->limit(30)
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->lesson->localStart()->format('D j M'),
                'className' => $record->lesson->classGroup->name,
                'time' => $record->lesson->localStart()->format('H:i').'–'.$record->lesson->localEnd()->format('H:i'),
                'status' => $record->status->value,
                'minutesLate' => $record->minutes_late,
            ]);

        return Inertia::render('children/attendance', [
            'child' => [
                'id' => $child->id,
                'name' => $child->name,
                'yearGroup' => $child->studentProfile?->year_group->label(),
            ],
            'stats' => $stats->forUser($child),
            'records' => $records,
        ]);
    }
}
```

In `ChildController::index`, inject `AttendanceStats $stats` and add `'attendance' => $stats->forUser($child)['attendance']` and `'late' => $stats->forUser($child)['late']` to each row. Compute it once per child: `$summary = $stats->forUser($child);`.

- [ ] **Step 4: UI**

`resources/js/pages/children/attendance.tsx`:

- `AppLayout` with title `"{name}'s attendance"`, `badge={yearGroup}`, and subtitle "Last 30 days".
- Three `StatCard`s: Attendance (`chart`, royal), On time (`check`, present), and Late arrivals (`clock`, gold, value `"{late}"`, hint `"{absent} absent"`).
- A table of records: date, class, time, and an `AttendanceStatusBadge` with `minutesLate`.
- An empty state: "No classes recorded yet."
- A "Back to children" link.

`children/index.tsx`: in each child card, show "{attendance}% attendance" (or "No classes recorded yet") and, when `late > 0`, a `late` badge with "{late} late". Add a "View attendance" link (`attendance(child.id)` from `@/routes/children`) next to Edit.

- [ ] **Step 5: Run the tests to verify they pass, then do the final checks**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/ChildAttendanceTest.php tests/Feature/ChildManagementTest.php
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check && npm run build
php artisan migrate --no-interaction
php artisan test --compact
```

Expected: all green. Then do visual checks with headless Chrome at 1280 and 360 (the scratchpad `shot.mjs`) of:

- the report page (student and tutor);
- the admin Reports and Lateness pages;
- the parent attendance page.

Commit the touched files with `feat: parents see their children's attendance`.
