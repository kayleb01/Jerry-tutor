# Phase 1: Domain Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add roles, year groups and the year → difficulty rule, student profiles and guardian ↔ student links. Every later phase builds on these.

**Architecture:** String- and int-backed PHP enums in `app/Enums` hold the domain vocabulary, and the year → key stage → difficulty rules live on the `YearGroup` enum, so there's one source of truth. `users` gains a `role` column. Student-only data lives in `student_profiles` (1:1), and the guardian relationship is a self-referencing `guardian_student` pivot on `users`.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 5, SQLite. No new dependencies.

**Spec:** [docs/architecture/database-schema.md](../../architecture/database-schema.md) §2–3, [docs/design/design-brief.md](../../design/design-brief.md) §2

## Global Constraints

- Enum keys are TitleCase (`Year3`, `Ks2`, `ElevenPlus`). Values are lowercase strings, except `YearGroup`, which is int-backed (3–11).
- Store enum columns as `string` / `unsignedTinyInteger` in the database, never as DB `enum`.
- Use the model attribute style already in `app/Models/User.php` (`#[Fillable]`, `#[Hidden]`) and a `casts()` method.
- Give every method explicit return types, and add PHPDoc for relationship generics.
- Create files with `php artisan make:* --no-interaction`.
- After PHP changes run `vendor/bin/pint --format agent`, and run tests with `php artisan test --compact <path>`.
- The folder isn't a git repo yet. Run `git init && git add -A && git commit -m "chore: initial scaffold"` once before Task 1 (roadmap decision D8). If git isn't wanted, skip the commit steps.

## Review Focus

1. **Year outside 3–11** (e.g. 2 or 12) must be rejected (`ValueError` from `YearGroup::from`), not silently stored. → Task 1 test.
2. **The Challenging tier outside KS3**: a Year 5 or Year 10 student with `challenging_tier_unlocked = true` must _not_ get the tier. → Task 3 test.
3. **Linking the wrong roles** (a tutor as guardian, a guardian as child, or linking a user to themselves) must throw. → Task 4 test.
4. **Linking the same child twice** must not create duplicates or crash. → Task 4 test.
5. **Deleting a student** removes their profile and guardian links (cascade), with no orphans. → Task 3 and Task 4 tests.

---

## File Structure

| File                                                                                                            | Responsibility                                         |
| --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `app/Enums/Role.php`                                                                                            | Admin / Tutor / Student / Guardian                     |
| `app/Enums/KeyStage.php`                                                                                        | Ks2 / Ks3 / Ks4 plus labels                            |
| `app/Enums/Difficulty.php`                                                                                      | Easy → Challenging plus labels                         |
| `app/Enums/ExamTrack.php`                                                                                       | ElevenPlus / Sats / Gcse plus labels                   |
| `app/Enums/YearGroup.php`                                                                                       | Year 3–11 → key stage, default difficulty              |
| `database/migrations/*_add_role_to_users_table.php`                                                             | `users.role`                                           |
| `database/migrations/*_create_student_profiles_table.php`                                                       | `student_profiles`                                     |
| `database/migrations/*_create_guardian_student_table.php`                                                       | the pivot                                              |
| `app/Models/User.php`                                                                                           | role cast, `hasRole()`, relationships, `linkChild()`   |
| `app/Models/StudentProfile.php`                                                                                 | year group, `hasChallengingTier()`                     |
| `database/factories/UserFactory.php`                                                                            | `admin()`, `tutor()`, `guardian()`, `student()` states |
| `database/factories/StudentProfileFactory.php`                                                                  | profile factory                                        |
| `database/seeders/DatabaseSeeder.php`                                                                           | demo accounts per role                                 |
| `tests/Unit/YearGroupTest.php`                                                                                  | the enum rules                                         |
| `tests/Feature/UserRoleTest.php`, `StudentProfileTest.php`, `GuardianStudentTest.php`, `DatabaseSeederTest.php` | behaviour                                              |

---

### Task 1: Domain enums

**Files:**

- Create: `app/Enums/Role.php`, `app/Enums/KeyStage.php`, `app/Enums/Difficulty.php`, `app/Enums/ExamTrack.php`, `app/Enums/YearGroup.php`
- Test: `tests/Unit/YearGroupTest.php`

**Interfaces:**

- Produces: `Role::{Admin,Tutor,Student,Guardian}` with values `'admin'|'tutor'|'student'|'guardian'`; `KeyStage::{Ks2,Ks3,Ks4}->label(): string`; `Difficulty::{Easy,Intermediate,Hard,Challenging}` with values `'easy'|'intermediate'|'hard'|'challenging'` and `->label()`; `ExamTrack::{ElevenPlus,Sats,Gcse}` with `->label()`; `YearGroup::Year3…Year11` (int 3–11) with `keyStage(): KeyStage`, `defaultDifficulty(): ?Difficulty`, `label(): string`.

- [ ] **Step 1: Write the failing test**

Run `php artisan make:test --pest --unit YearGroupTest --no-interaction`, then replace its contents:

```php
<?php

use App\Enums\Difficulty;
use App\Enums\KeyStage;
use App\Enums\YearGroup;

it('maps each year group to its key stage', function (int $year, KeyStage $keyStage) {
    expect(YearGroup::from($year)->keyStage())->toBe($keyStage);
})->with([
    [3, KeyStage::Ks2], [4, KeyStage::Ks2], [5, KeyStage::Ks2], [6, KeyStage::Ks2],
    [7, KeyStage::Ks3], [8, KeyStage::Ks3], [9, KeyStage::Ks3],
    [10, KeyStage::Ks4], [11, KeyStage::Ks4],
]);

it('maps each year group to the brief\'s default difficulty', function (int $year, ?Difficulty $difficulty) {
    expect(YearGroup::from($year)->defaultDifficulty())->toBe($difficulty);
})->with([
    [3, Difficulty::Easy], [4, Difficulty::Easy], [5, Difficulty::Intermediate], [6, Difficulty::Hard],
    [7, Difficulty::Easy], [8, Difficulty::Intermediate], [9, Difficulty::Hard],
    [10, null], [11, null],
]);

it('labels year groups for display', function () {
    expect(YearGroup::Year6->label())->toBe('Year 6');
});

it('rejects years outside 3 to 11', function (int $year) {
    YearGroup::from($year);
})->with([2, 12])->throws(ValueError::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Unit/YearGroupTest.php`
Expected: FAIL with `Class "App\Enums\YearGroup" not found`.

- [ ] **Step 3: Write the enums**

`php artisan make:enum Role --string --no-interaction` (repeat for each; if the `--string`/`--int` flags aren't available, check with `php artisan make:enum --help`), then set the contents:

`app/Enums/Role.php`

```php
<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Tutor = 'tutor';
    case Student = 'student';
    case Guardian = 'guardian';
}
```

`app/Enums/KeyStage.php`

```php
<?php

namespace App\Enums;

enum KeyStage: string
{
    case Ks2 = 'ks2';
    case Ks3 = 'ks3';
    case Ks4 = 'ks4';

    public function label(): string
    {
        return match ($this) {
            self::Ks2 => 'KS2',
            self::Ks3 => 'KS3',
            self::Ks4 => 'GCSE',
        };
    }
}
```

`app/Enums/Difficulty.php`

```php
<?php

namespace App\Enums;

enum Difficulty: string
{
    case Easy = 'easy';
    case Intermediate = 'intermediate';
    case Hard = 'hard';
    case Challenging = 'challenging';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
```

`app/Enums/ExamTrack.php`

```php
<?php

namespace App\Enums;

enum ExamTrack: string
{
    case ElevenPlus = 'eleven_plus';
    case Sats = 'sats';
    case Gcse = 'gcse';

    public function label(): string
    {
        return match ($this) {
            self::ElevenPlus => '11+',
            self::Sats => 'Year 6 SATs',
            self::Gcse => 'GCSE',
        };
    }
}
```

`app/Enums/YearGroup.php`

```php
<?php

namespace App\Enums;

enum YearGroup: int
{
    case Year3 = 3;
    case Year4 = 4;
    case Year5 = 5;
    case Year6 = 6;
    case Year7 = 7;
    case Year8 = 8;
    case Year9 = 9;
    case Year10 = 10;
    case Year11 = 11;

    public function keyStage(): KeyStage
    {
        return match ($this) {
            self::Year3, self::Year4, self::Year5, self::Year6 => KeyStage::Ks2,
            self::Year7, self::Year8, self::Year9 => KeyStage::Ks3,
            self::Year10, self::Year11 => KeyStage::Ks4,
        };
    }

    /**
     * Default quiz difficulty per the design brief. GCSE years use exam tracks instead.
     */
    public function defaultDifficulty(): ?Difficulty
    {
        return match ($this) {
            self::Year3, self::Year4, self::Year7 => Difficulty::Easy,
            self::Year5, self::Year8 => Difficulty::Intermediate,
            self::Year6, self::Year9 => Difficulty::Hard,
            self::Year10, self::Year11 => null,
        };
    }

    public function label(): string
    {
        return "Year {$this->value}";
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Unit/YearGroupTest.php`
Expected: PASS (21 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --format agent
git add app/Enums tests/Unit/YearGroupTest.php
git commit -m "feat: add domain enums with year group difficulty mapping"
```

---

### Task 2: Roles on users

**Files:**

- Create: `database/migrations/<timestamp>_add_role_to_users_table.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/UserRoleTest.php`

**Interfaces:**

- Consumes: `App\Enums\Role` (Task 1).
- Produces: `User::$role` cast to `Role`; `User::hasRole(Role ...$roles): bool`; factory states `admin()`, `tutor()`, `guardian()` (the default role is `Guardian`).

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest UserRoleTest --no-interaction`:

```php
<?php

use App\Enums\Role;
use App\Models\User;

it('defaults new users to the guardian role', function () {
    $user = User::factory()->create();

    expect($user->fresh()->role)->toBe(Role::Guardian);
});

it('casts the role column to the Role enum', function () {
    $user = User::factory()->tutor()->create();

    expect($user->fresh()->role)->toBe(Role::Tutor);
});

it('checks a user against one or more roles', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasRole(Role::Admin))->toBeTrue()
        ->and($admin->hasRole(Role::Tutor, Role::Admin))->toBeTrue()
        ->and($admin->hasRole(Role::Student))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/UserRoleTest.php`
Expected: FAIL with `Call to undefined method ... tutor()`.

- [ ] **Step 3: Migration**

`php artisan make:migration add_role_to_users_table --table=users --no-interaction`, then:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('role')->default('guardian')->after('email')->index();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex(['role']);
        $table->dropColumn('role');
    });
}
```

- [ ] **Step 4: Model**

In `app/Models/User.php`: add `use App\Enums\Role;`, add `@property Role $role` to the docblock, change the attribute to `#[Fillable(['name', 'email', 'password', 'role'])]`, add `'role' => Role::class,` to `casts()`, and add:

```php
public function hasRole(Role ...$roles): bool
{
    return in_array($this->role, $roles, true);
}
```

- [ ] **Step 5: Factory states**

In `database/factories/UserFactory.php`: add `use App\Enums\Role;`, add `'role' => Role::Guardian,` to `definition()`, and add:

```php
public function admin(): static
{
    return $this->state(fn (array $attributes) => ['role' => Role::Admin]);
}

public function tutor(): static
{
    return $this->state(fn (array $attributes) => ['role' => Role::Tutor]);
}

public function guardian(): static
{
    return $this->state(fn (array $attributes) => ['role' => Role::Guardian]);
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/UserRoleTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --format agent
git add app/Models/User.php database/migrations database/factories/UserFactory.php tests/Feature/UserRoleTest.php
git commit -m "feat: add roles to users"
```

---

### Task 3: Student profiles

**Files:**

- Create: `app/Models/StudentProfile.php`, `database/migrations/<timestamp>_create_student_profiles_table.php`, `database/factories/StudentProfileFactory.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/StudentProfileTest.php`

**Interfaces:**

- Consumes: `Role`, `YearGroup`, `KeyStage`, `Difficulty` (Task 1); `User::factory()` states (Task 2).
- Produces: `StudentProfile` (`user_id`, `year_group: YearGroup`, `date_of_birth: ?Carbon`, `school_name: ?string`, `challenging_tier_unlocked: bool`); `StudentProfile::user(): BelongsTo`; `StudentProfile::hasChallengingTier(): bool`; `User::studentProfile(): HasOne`; `UserFactory::student(YearGroup $yearGroup = YearGroup::Year5): static`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest StudentProfileTest --no-interaction`:

```php
<?php

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\StudentProfile;
use App\Models\User;

it('creates a student with a profile for the given year group', function () {
    $student = User::factory()->student(YearGroup::Year7)->create();

    expect($student->role)->toBe(Role::Student)
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year7);
});

it('grants the challenging tier only to unlocked KS3 students', function (YearGroup $yearGroup, bool $unlocked, bool $expected) {
    $profile = StudentProfile::factory()->create([
        'year_group' => $yearGroup,
        'challenging_tier_unlocked' => $unlocked,
    ]);

    expect($profile->hasChallengingTier())->toBe($expected);
})->with([
    'year 8 unlocked' => [YearGroup::Year8, true, true],
    'year 9 locked' => [YearGroup::Year9, false, false],
    'year 5 unlocked (KS2)' => [YearGroup::Year5, true, false],
    'year 10 unlocked (KS4)' => [YearGroup::Year10, true, false],
]);

it('deletes the profile when the student is deleted', function () {
    $student = User::factory()->student()->create();

    $student->delete();

    expect(StudentProfile::count())->toBe(0);
});

it('allows only one profile per user', function () {
    $student = User::factory()->student()->create();

    StudentProfile::factory()->for($student)->create();
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/StudentProfileTest.php`
Expected: FAIL with `Class "App\Models\StudentProfile" not found`.

- [ ] **Step 3: Model, migration, factory**

`php artisan make:model StudentProfile --migration --factory --no-interaction`

Migration `up()`:

```php
Schema::create('student_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('year_group');
    $table->date('date_of_birth')->nullable();
    $table->string('school_name')->nullable();
    $table->boolean('challenging_tier_unlocked')->default(false);
    $table->timestamps();
});
```

`app/Models/StudentProfile.php`:

```php
<?php

namespace App\Models;

use App\Enums\KeyStage;
use App\Enums\YearGroup;
use Database\Factories\StudentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property YearGroup $year_group
 * @property Carbon|null $date_of_birth
 * @property string|null $school_name
 * @property bool $challenging_tier_unlocked
 */
#[Fillable(['user_id', 'year_group', 'date_of_birth', 'school_name', 'challenging_tier_unlocked'])]
class StudentProfile extends Model
{
    /** @use HasFactory<StudentProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_group' => YearGroup::class,
            'date_of_birth' => 'date',
            'challenging_tier_unlocked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Challenging tier is a KS3-only extra level.
     */
    public function hasChallengingTier(): bool
    {
        return $this->challenging_tier_unlocked
            && $this->year_group->keyStage() === KeyStage::Ks3;
    }
}
```

`database/factories/StudentProfileFactory.php` `definition()`:

```php
return [
    'user_id' => User::factory()->state(['role' => Role::Student]),
    'year_group' => fake()->randomElement(YearGroup::cases()),
    'date_of_birth' => null,
    'school_name' => null,
    'challenging_tier_unlocked' => false,
];
```

(imports: `App\Enums\Role`, `App\Enums\YearGroup`, `App\Models\User`)

- [ ] **Step 4: User relationship and factory state**

`app/Models/User.php`, with `use Illuminate\Database\Eloquent\Relations\HasOne;`:

```php
/**
 * @return HasOne<StudentProfile, $this>
 */
public function studentProfile(): HasOne
{
    return $this->hasOne(StudentProfile::class);
}
```

`database/factories/UserFactory.php`, with `use App\Enums\YearGroup;` and `use App\Models\StudentProfile;`:

```php
public function student(YearGroup $yearGroup = YearGroup::Year5): static
{
    return $this->state(fn (array $attributes) => ['role' => Role::Student])
        ->afterCreating(fn (User $user) => StudentProfile::factory()
            ->for($user)
            ->create(['year_group' => $yearGroup]));
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/StudentProfileTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --format agent
git add app/Models database/migrations database/factories tests/Feature/StudentProfileTest.php
git commit -m "feat: add student profiles with year group and challenging tier"
```

---

### Task 4: Guardian ↔ student links

**Files:**

- Create: `database/migrations/<timestamp>_create_guardian_student_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/GuardianStudentTest.php`

**Interfaces:**

- Consumes: `Role`, `User::hasRole()` (Task 2); `UserFactory::student()` (Task 3).
- Produces: `User::children(): BelongsToMany` (guardian → students); `User::guardians(): BelongsToMany` (student → guardians); `User::linkChild(User $student, ?string $relationship = null): void`, which throws `InvalidArgumentException` on wrong roles or a self-link and is idempotent.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest GuardianStudentTest --no-interaction`:

```php
<?php

use App\Models\User;

it('links a guardian to a child both ways', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();

    $guardian->linkChild($student, 'Mother');

    expect($guardian->children->pluck('id')->all())->toBe([$student->id])
        ->and($student->guardians->pluck('id')->all())->toBe([$guardian->id])
        ->and($guardian->children->first()->pivot->relationship)->toBe('Mother');
});

it('ignores linking the same child twice', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();

    $guardian->linkChild($student);
    $guardian->linkChild($student);

    expect($guardian->children()->count())->toBe(1);
});

it('refuses links between the wrong roles', function (string $guardianState, string $childState) {
    $guardian = User::factory()->{$guardianState}()->create();
    $child = User::factory()->{$childState}()->create();

    $guardian->linkChild($child);
})->with([
    'tutor as guardian' => ['tutor', 'student'],
    'guardian as child' => ['guardian', 'guardian'],
    'admin as child' => ['guardian', 'admin'],
])->throws(InvalidArgumentException::class);

it('refuses linking a user to themselves', function () {
    $guardian = User::factory()->guardian()->create();

    $guardian->linkChild($guardian);
})->throws(InvalidArgumentException::class);

it('removes links when the student is deleted', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();
    $guardian->linkChild($student);

    $student->delete();

    expect($guardian->children()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/GuardianStudentTest.php`
Expected: FAIL with `Call to undefined method App\Models\User::linkChild()`.

- [ ] **Step 3: Migration**

`php artisan make:migration create_guardian_student_table --no-interaction`:

```php
Schema::create('guardian_student', function (Blueprint $table) {
    $table->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
    $table->string('relationship')->nullable();
    $table->timestamps();

    $table->primary(['guardian_id', 'student_id']);
});
```

- [ ] **Step 4: Relationships and `linkChild()`**

`app/Models/User.php`, with `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and `use InvalidArgumentException;`:

```php
/**
 * @return BelongsToMany<User, $this>
 */
public function children(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'guardian_student', 'guardian_id', 'student_id')
        ->withPivot('relationship')
        ->withTimestamps();
}

/**
 * @return BelongsToMany<User, $this>
 */
public function guardians(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'guardian_student', 'student_id', 'guardian_id')
        ->withPivot('relationship')
        ->withTimestamps();
}

/**
 * @throws InvalidArgumentException
 */
public function linkChild(User $student, ?string $relationship = null): void
{
    if ($this->is($student) || ! $this->hasRole(Role::Guardian) || ! $student->hasRole(Role::Student)) {
        throw new InvalidArgumentException('Only a guardian can be linked to a different student.');
    }

    $this->children()->syncWithoutDetaching([
        $student->id => ['relationship' => $relationship],
    ]);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/GuardianStudentTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --format agent
git add app/Models/User.php database/migrations tests/Feature/GuardianStudentTest.php
git commit -m "feat: link guardians to students"
```

---

### Task 5: Demo seeder

**Files:**

- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/DatabaseSeederTest.php`

**Interfaces:**

- Consumes: every factory state from Tasks 2–4, and `linkChild()`.
- Produces: demo logins (password `password`): `admin@academy.test`, `tutor@academy.test`, `parent@academy.test` (guardian of `ks2.student@academy.test` in Year 5 and `ks3.student@academy.test` in Year 8).

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest DatabaseSeederTest --no-interaction`:

```php
<?php

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\User;

it('seeds one demo account per role with linked children', function () {
    $this->seed();

    expect(User::firstWhere('email', 'admin@academy.test')->role)->toBe(Role::Admin)
        ->and(User::firstWhere('email', 'tutor@academy.test')->role)->toBe(Role::Tutor);

    $parent = User::firstWhere('email', 'parent@academy.test');

    expect($parent->children->pluck('studentProfile.year_group')->all())
        ->toEqualCanonicalizing([YearGroup::Year5, YearGroup::Year8]);
});

it('can run twice without failing', function () {
    $this->seed();
    $this->seed();

    expect(User::where('email', 'parent@academy.test')->count())->toBe(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/DatabaseSeederTest.php`
Expected: FAIL (the `admin@academy.test` lookup returns null).

- [ ] **Step 3: Seeder**

Replace the `run()` body in `database/seeders/DatabaseSeeder.php` (keep the existing imports; add `use App\Enums\Role;` and `use App\Enums\YearGroup;`):

```php
public function run(): void
{
    if (User::where('email', 'admin@academy.test')->exists()) {
        return;
    }

    User::factory()->admin()->create(['name' => 'Academy Admin', 'email' => 'admin@academy.test']);
    User::factory()->tutor()->create(['name' => 'Demo Tutor', 'email' => 'tutor@academy.test']);

    $parent = User::factory()->guardian()->create(['name' => 'Demo Parent', 'email' => 'parent@academy.test']);

    $parent->linkChild(
        User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava (Year 5)', 'email' => 'ks2.student@academy.test']),
        'Parent',
    );
    $parent->linkChild(
        User::factory()->student(YearGroup::Year8)->create(['name' => 'Leo (Year 8)', 'email' => 'ks3.student@academy.test']),
        'Parent',
    );
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/DatabaseSeederTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Full phase check, format, commit**

```bash
vendor/bin/pint --format agent
php artisan test --compact
php artisan migrate:fresh --seed --no-interaction
git add database/seeders tests/Feature/DatabaseSeederTest.php
git commit -m "feat: seed demo accounts for each role"
```

Expected: all tests pass, and the local DB contains 5 demo users.
