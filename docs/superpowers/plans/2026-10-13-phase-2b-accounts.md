# Phase 2b: Family & Admin Account Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guardians can add and manage their children's student accounts. Admins can find, create, edit, deactivate, restore and reset the password of any user. Accounts are soft-deleted so history survives, and the auth gaps the 2a review deferred are closed.

**Architecture:** `User` gains `SoftDeletes` ("deactivated") and lowercase mutators for `email` and `username`. One `CreateStudentAccount` action, which runs in a transaction, serves both the guardian and admin flows. Validation rules shared across the form requests live in an `AccountValidationRules` trait, built on Fortify's `PasswordValidationRules`. Guardians reach children through a guardian-scoped route binding (`{child}`), so another family's child gives a 404. Admin routes live under `/admin` behind `role:admin`. Success and error toasts use Inertia v3 flash data (`Inertia::flash('toast', …)`).

**Tech Stack:** Laravel 13, Fortify 1.41, Inertia v3, React 19, Wayfinder, Tailwind v4, Pest 5.

**Spec:** [docs/plans/implementation-plan.md](../../plans/implementation-plan.md) Phase 2 · [docs/architecture/database-schema.md](../../architecture/database-schema.md) §3 · [docs/design/design-brief.md](../../design/design-brief.md) §2, §4–6 · Phase 2a ledger constraints (memory `phase-2b-constraints`)

## Global Constraints

- **Usernames:** 3–30 characters matching `^[a-z0-9._-]+$`, unique, stored lowercase. The login code treats any identifier containing `@` as an email, so a username must never contain one.
- **Emails are stored lowercase on every write path** (an `Attribute` mutator on `User`). Form requests also lowercase `email` and `username` in `prepareForValidation()`, so `unique` checks see the stored form. SQLite's `=` is case-sensitive.
- **`role` stays non-fillable.** Set it only with `forceFill`. A user's role can't be changed after creation (v1).
- **Deactivating is a soft delete.** The profile, guardian links and (later) attendance history are kept. A deactivated user can't log in, and any existing session stops working. There's no hard delete in the UI.
- Accounts created by a guardian or admin are **trusted**: if an email is given, `email_verified_at` is set to now. Staff accounts (admin, tutor) always need an email. Students always need a username, and their email is optional.
- Guardians get a **404, not a 403**, for children that aren't theirs. That way they can't probe which ids exist.
- UK English and a friendly tone. Every input has a `<Label>`, `aria-invalid` and `aria-describedby` (via `FieldError`). Use design-system tokens and components only.
- Use Wayfinder imports (`@/routes/...`); never hard-code URLs. Run `php artisan wayfinder:generate --with-form` after route changes.
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `npm run check:fix && npm run types:check` for TS/TSX changes. Run tests with `php artisan test --compact <path>`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Mixed-case duplicates**: creating `Ava.Okafor` when `ava.okafor` exists, or `Parent@X.com` when `parent@x.com` exists, must fail validation with a field error, not a 500 from the unique index. → Task 3 and Task 4 tests.
2. **Another family's child**: a guardian requesting `/children/{id}/edit`, `PUT /children/{id}` or `PUT /children/{id}/password` for someone else's child gets a 404 and nothing changes. → Task 3 test.
3. **Partial account creation**: if linking the new child fails, no orphan student user or profile is left behind. → Task 2 test.
4. **Deactivated accounts**: they can't log in, an existing session is ended, and an admin can't deactivate themselves. → Task 1 and Task 5 tests.
5. **Role escalation through edit forms**: posting `role=admin` to any update endpoint changes nothing. → Task 3 and Task 4 tests.

---

## File Structure

| File                                                                                 | Responsibility                                                         |
| ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------- |
| `database/migrations/*_add_soft_deletes_to_users_table.php`                          | `users.deleted_at`                                                     |
| `app/Models/User.php`                                                                | `SoftDeletes`, lowercase `email`/`username` mutators                   |
| `app/Enums/YearGroup.php`                                                            | `options()` for selects                                                |
| `app/Concerns/AccountValidationRules.php`                                            | username, email, year-group and password rules, plus input normalising |
| `app/Actions/CreateStudentAccount.php`                                               | transactional student creation (+ optional guardian link)              |
| `app/Http/Controllers/ChildController.php`                                           | guardian: index, create, store, edit, update                           |
| `app/Http/Controllers/ChildPasswordController.php`                                   | guardian: reset a child's password                                     |
| `app/Http/Requests/{StoreChildRequest,UpdateChildRequest,UpdatePasswordRequest}.php` | guardian validation                                                    |
| `app/Http/Controllers/Admin/UserController.php`                                      | admin: index, create, store, edit, update, destroy                     |
| `app/Http/Controllers/Admin/RestoreUserController.php`                               | admin: restore                                                         |
| `app/Http/Controllers/Admin/UserPasswordController.php`                              | admin: set a password                                                  |
| `app/Http/Requests/Admin/{StoreUserRequest,UpdateUserRequest}.php`                   | admin validation                                                       |
| `app/Providers/AppServiceProvider.php`                                               | guardian-scoped `{child}` binding                                      |
| `app/Http/Responses/NeutralPasswordResetLinkResponse.php`                            | no email enumeration                                                   |
| `resources/js/components/ui/select.tsx`                                              | native select, styled                                                  |
| `resources/js/components/toast.tsx`                                                  | flash toast                                                            |
| `resources/js/components/student-fields.tsx`                                         | shared student form fields                                             |
| `resources/js/layouts/app-layout.tsx`                                                | role nav plus toast                                                    |
| `resources/js/pages/children/{index,create,edit}.tsx`                                | guardian screens                                                       |
| `resources/js/pages/admin/users/{index,create,edit}.tsx`                             | admin screens                                                          |
| `resources/js/pages/auth/confirm-password.tsx`                                       | fixes the 2a 500                                                       |
| `resources/js/types/{auth,index,global.d}.ts`, `resources/js/types/ui.ts`            | `Paginated<T>`, `Option`, flash type                                   |

---

### Task 1: Soft deletes and lowercase identifiers on users

**Files:**

- Create: `database/migrations/<ts>_add_soft_deletes_to_users_table.php`
- Modify: `app/Models/User.php`, `tests/Feature/StudentProfileTest.php`, `tests/Feature/GuardianStudentTest.php`
- Test: `tests/Feature/UserAccountTest.php`

**Interfaces:**

- Consumes: `User`, the factory states, and Fortify login (2a).
- Produces: `User` uses `SoftDeletes` (`delete()` deactivates, `restore()` reactivates, `trashed()`); `email` and `username` are lowercased and trimmed when set.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest UserAccountTest --no-interaction`:

```php
<?php

use App\Models\User;

it('stores emails and usernames in lowercase', function () {
    $user = User::factory()->student()->create([
        'email' => '  Mixed@Example.COM ',
        'username' => 'Ava.Okafor',
    ]);

    expect($user->fresh())
        ->email->toBe('mixed@example.com')
        ->username->toBe('ava.okafor');
});

it('logs in with a mixed-case email', function () {
    $user = User::factory()->create(['email' => 'parent@example.com']);

    $this->post(route('login.store'), ['login' => 'Parent@Example.COM', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('stops a deactivated user from logging in', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $user->delete();

    $this->post(route('login.store'), ['login' => 'gone@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('ends the session of a user who is deactivated', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $this->post(route('login.store'), ['login' => 'gone@example.com', 'password' => 'password']);

    $user->delete();
    $this->app['auth']->forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('keeps profile and guardian links when a student is deactivated, and restores them', function () {
    $guardian = User::factory()->guardian()->create();
    $student = User::factory()->student()->create();
    $guardian->linkChild($student);

    $student->delete();

    expect($student->fresh()->trashed())->toBeTrue();
    $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id]);
    $this->assertDatabaseHas('guardian_student', ['student_id' => $student->id]);

    $student->restore();

    expect($guardian->children()->pluck('users.id')->all())->toBe([$student->id]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/UserAccountTest.php`
Expected: FAIL. The emails aren't lowercased, `trashed()` is undefined, and deleted users can still log in.

- [ ] **Step 3: Migration**

`php artisan make:migration add_soft_deletes_to_users_table --table=users --no-interaction`:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->softDeletes();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropSoftDeletes();
    });
}
```

- [ ] **Step 4: Model**

In `app/Models/User.php`:

- add the imports `use Illuminate\Database\Eloquent\Casts\Attribute;`, `use Illuminate\Database\Eloquent\SoftDeletes;` and `use Illuminate\Support\Str;`;
- add `@property Carbon|null $deleted_at` to the docblock;
- change the trait line to `use HasFactory, Notifiable, SoftDeletes;`;
- add:

```php
/**
 * Emails are stored lowercase so login and uniqueness checks are case-insensitive.
 */
protected function email(): Attribute
{
    return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : Str::lower(trim($value)));
}

/**
 * Usernames are stored lowercase so login and uniqueness checks are case-insensitive.
 */
protected function username(): Attribute
{
    return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : Str::lower(trim($value)));
}
```

- [ ] **Step 5: Update the Phase 1 cascade tests to hard deletes**

`delete()` is now a soft delete, so the FK cascades only fire on `forceDelete()`. (This is the cost the Phase 1 ruling named.)

- In `tests/Feature/StudentProfileTest.php`, test `deletes the profile when the student is deleted`: change `$student->delete();` to `$student->forceDelete();` and rename the test to `deletes the profile when the student is permanently deleted`.
- In `tests/Feature/GuardianStudentTest.php`, test `removes links when the student is deleted`: change to `$student->forceDelete();`, rename it to `removes links when the student is permanently deleted`, and replace its assertion with `$this->assertDatabaseCount('guardian_student', 0);`. This also closes the 2a orphan-detection minor.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/UserAccountTest.php tests/Feature/StudentProfileTest.php tests/Feature/GuardianStudentTest.php
```

Expected: PASS (5 + 7 + 7 = 19). If `ends the session…` still authenticates, it means the guard cached the user in memory. Confirm that `forgetGuards()` ran before the request; don't weaken the assertion.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
git add -A && git commit -m "feat: soft-delete users and store identifiers in lowercase"
```

---

### Task 2: Shared account rules and the `CreateStudentAccount` action

**Files:**

- Create: `app/Concerns/AccountValidationRules.php`, `app/Actions/CreateStudentAccount.php`
- Modify: `app/Enums/YearGroup.php`
- Test: `tests/Feature/CreateStudentAccountTest.php`, `tests/Unit/YearGroupTest.php`

**Interfaces:**

- Consumes: `User` (Task 1), `User::linkChild()`, `StudentProfile`, `YearGroup`, `Role`, and `App\Actions\Fortify\PasswordValidationRules`.
- Produces:
    - `YearGroup::options(): array<int, array{value: int, label: string}>`;
    - trait `AccountValidationRules` with `usernameRules(?User $ignore = null): array`, `emailRules(bool $required, ?User $ignore = null): array`, `yearGroupRules(): array`, `passwordRules(): array` (inherited from the Fortify trait), and `normaliseAccountInput(): void` for use in `prepareForValidation()`;
    - `CreateStudentAccount::handle(array $data, ?User $guardian = null, ?string $relationship = null): User`, where `$data` is `array{name: string, username: string, email?: string|null, password: string, year_group: int|string, date_of_birth?: string|null}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/YearGroupTest.php`:

```php
it('lists year groups as select options', function () {
    expect(YearGroup::options())
        ->toHaveCount(9)
        ->and(YearGroup::options()[0])->toBe(['value' => 3, 'label' => 'Year 3']);
});
```

`php artisan make:test --pest CreateStudentAccountTest --no-interaction`:

```php
<?php

use App\Actions\CreateStudentAccount;
use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a student with a profile', function () {
    $student = app(CreateStudentAccount::class)->handle([
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'year_group' => '5',
    ]);

    expect($student->role)->toBe(Role::Student)
        ->and($student->email)->toBeNull()
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year5)
        ->and(Hash::check('a-strong-password', $student->password))->toBeTrue();
});

it('marks a provided email as verified because a trusted adult created the account', function () {
    $student = app(CreateStudentAccount::class)->handle([
        'name' => 'Leo Okafor',
        'username' => 'leo.okafor',
        'email' => 'leo@example.com',
        'password' => 'a-strong-password',
        'year_group' => 8,
    ]);

    expect($student->hasVerifiedEmail())->toBeTrue()
        ->and($student->email_verified_at)->not->toBeNull();
});

it('links the new student to the guardian', function () {
    $guardian = User::factory()->guardian()->create();

    $student = app(CreateStudentAccount::class)->handle([
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'year_group' => 5,
    ], $guardian, 'Mother');

    expect($guardian->children()->first()->is($student))->toBeTrue();
});

it('leaves nothing behind if linking fails', function () {
    $tutor = User::factory()->tutor()->create();

    expect(fn () => app(CreateStudentAccount::class)->handle([
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'year_group' => 5,
    ], $tutor))->toThrow(InvalidArgumentException::class);

    expect(User::where('username', 'ava.okafor')->exists())->toBeFalse()
        ->and(StudentProfile::count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/YearGroupTest.php tests/Feature/CreateStudentAccountTest.php`
Expected: FAIL. `YearGroup::options` is undefined, and the `App\Actions\CreateStudentAccount` class doesn't exist.

- [ ] **Step 3: `YearGroup::options()`**

Add to `app/Enums/YearGroup.php`:

```php
/**
 * @return array<int, array{value: int, label: string}>
 */
public static function options(): array
{
    return array_map(
        fn (self $yearGroup): array => ['value' => $yearGroup->value, 'label' => $yearGroup->label()],
        self::cases(),
    );
}
```

- [ ] **Step 4: The rules trait**

`app/Concerns/AccountValidationRules.php` (create the `app/Concerns` directory):

```php
<?php

namespace App\Concerns;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\YearGroup;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation shared by every form that creates or edits an account.
 * Use it from a FormRequest.
 */
trait AccountValidationRules
{
    use PasswordValidationRules;

    /**
     * Lowercase identifiers before validation so `unique` sees the stored form.
     */
    protected function normaliseAccountInput(): void
    {
        $normalised = [];

        foreach (['email', 'username'] as $field) {
            if (is_string($this->input($field))) {
                $value = Str::lower(trim($this->input($field)));
                $normalised[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($normalised);
    }

    /**
     * @return array<int, mixed>
     */
    protected function usernameRules(?User $ignore = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:30',
            'regex:/^[a-z0-9._-]+$/',
            Rule::unique(User::class, 'username')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function emailRules(bool $required, ?User $ignore = null): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'email',
            'max:255',
            Rule::unique(User::class, 'email')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function yearGroupRules(): array
    {
        return ['required', Rule::enum(YearGroup::class)];
    }
}
```

`Rule::unique(...)` with no `withoutTrashed()` deliberately counts deactivated users, which matches the DB unique index. `Rule::enum(YearGroup::class)` accepts `'5'` and `5`.

- [ ] **Step 5: The action**

`php artisan make:class Actions/CreateStudentAccount --no-interaction`, then replace its contents:

```php
<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateStudentAccount
{
    /**
     * Create a student and their profile, optionally linked to a guardian, all or nothing.
     *
     * @param  array{name: string, username: string, email?: string|null, password: string, year_group: int|string, date_of_birth?: string|null}  $data
     */
    public function handle(array $data, ?User $guardian = null, ?string $relationship = null): User
    {
        return DB::transaction(function () use ($data, $guardian, $relationship): User {
            $email = $data['email'] ?? null;

            $student = new User([
                'name' => $data['name'],
                'email' => $email,
                'password' => Hash::make($data['password']),
            ]);
            $student->forceFill([
                'role' => Role::Student,
                'username' => $data['username'],
                'email_verified_at' => $email === null ? null : now(),
            ])->save();

            $student->studentProfile()->create([
                'year_group' => (int) $data['year_group'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
            ]);

            $guardian?->linkChild($student, $relationship);

            return $student;
        });
    }
}
```

If `make:class` places the file elsewhere (as `make:enum` did in Phase 1), move it to `app/Actions/CreateStudentAccount.php` and ledger it.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/YearGroupTest.php tests/Feature/CreateStudentAccountTest.php`
Expected: PASS (22 + 4 = 26).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: shared account rules and transactional student creation"
```

The trait is exercised through the form requests in Tasks 3–4.

---

### Task 3: Guardians manage their children

**Files:**

- Create: `app/Http/Controllers/ChildController.php`, `app/Http/Controllers/ChildPasswordController.php`, `app/Http/Requests/StoreChildRequest.php`, `app/Http/Requests/UpdateChildRequest.php`, `app/Http/Requests/UpdatePasswordRequest.php`, `resources/js/components/ui/select.tsx`, `resources/js/components/toast.tsx`, `resources/js/components/student-fields.tsx`, `resources/js/types/ui.ts`, `resources/js/pages/children/{index,create,edit}.tsx`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`, `resources/js/layouts/app-layout.tsx`, `resources/js/pages/dashboard/guardian.tsx`, `resources/js/types/index.ts`, `resources/js/types/global.d.ts`
- Test: `tests/Feature/ChildManagementTest.php`

**Interfaces:**

- Consumes: `AccountValidationRules` and `CreateStudentAccount` (Task 2), `YearGroup::options()`, middleware `role`.
- Produces:
    - routes `children.index|create|store|edit|update` and `children.password.update` (`PUT /children/{child}/password`), all behind `auth`, `verified`, `role:guardian`;
    - a guardian-scoped `{child}` binding;
    - flash key `toast: { type: 'success' | 'error'; message: string }`;
    - `UpdatePasswordRequest`, reused in Task 5;
    - components `Select`, `Toast`, `StudentFields({ errors, yearGroups, defaults? })`;
    - types `Option`, `Paginated<T>`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest ChildManagementTest --no-interaction`:

```php
<?php

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

function childPayload(array $overrides = []): array
{
    return [
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'email' => '',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'year_group' => 5,
        'relationship' => 'Mother',
        ...$overrides,
    ];
}

it('lists only the guardian\'s own children', function () {
    $guardian = User::factory()->guardian()->create();
    $mine = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava']);
    $guardian->linkChild($mine);
    User::factory()->student()->create(['name' => 'Someone Else']);

    $this->actingAs($guardian)->get(route('children.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/index')
            ->has('children', 1)
            ->where('children.0.name', 'Ava')
            ->where('children.0.yearGroup', 'Year 5'));
});

it('shows the add-child form with year groups', function () {
    $this->actingAs(User::factory()->guardian()->create())
        ->get(route('children.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/create')
            ->has('yearGroups', 9));
});

it('adds a child who can then log in with their username', function () {
    $guardian = User::factory()->guardian()->create();

    $this->actingAs($guardian)
        ->post(route('children.store'), childPayload(['username' => 'Ava.Okafor']))
        ->assertRedirect(route('children.index'))
        ->assertInertiaFlash('toast.type', 'success');

    $child = User::firstWhere('username', 'ava.okafor');
    expect($child->role)->toBe(Role::Student)
        ->and($guardian->children()->first()->is($child))->toBeTrue();

    auth()->logout();
    $this->post(route('login.store'), ['login' => 'ava.okafor', 'password' => 'a-strong-password'])
        ->assertRedirect('/dashboard');
});

it('rejects usernames that could be mistaken for an email or are badly formed', function (string $username) {
    $this->actingAs(User::factory()->guardian()->create())
        ->post(route('children.store'), childPayload(['username' => $username]))
        ->assertSessionHasErrors('username');
})->with(['ava@home', 'ava okafor', 'av', 'ávä']);

it('rejects a username that is taken, ignoring case', function () {
    User::factory()->student()->create(['username' => 'ava.okafor']);

    $this->actingAs(User::factory()->guardian()->create())
        ->post(route('children.store'), childPayload(['username' => 'AVA.OKAFOR']))
        ->assertSessionHasErrors('username');
});

it('rejects an email that is taken, ignoring case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs(User::factory()->guardian()->create())
        ->post(route('children.store'), childPayload(['email' => 'Taken@Example.com']))
        ->assertSessionHasErrors('email');
});

it('updates a child\'s details but never their role', function () {
    $guardian = User::factory()->guardian()->create();
    $child = User::factory()->student(YearGroup::Year5)->create(['username' => 'ava.okafor']);
    $guardian->linkChild($child);

    $this->actingAs($guardian)->put(route('children.update', $child), [
        'name' => 'Ava O.',
        'username' => 'ava.okafor',
        'email' => '',
        'year_group' => 6,
        'role' => 'admin',
    ])->assertRedirect(route('children.index'));

    $child->refresh();
    expect($child->name)->toBe('Ava O.')
        ->and($child->role)->toBe(Role::Student)
        ->and($child->studentProfile->year_group)->toBe(YearGroup::Year6);
});

it('resets a child\'s password', function () {
    $guardian = User::factory()->guardian()->create();
    $child = User::factory()->student()->create();
    $guardian->linkChild($child);

    $this->actingAs($guardian)->put(route('children.password.update', $child), [
        'password' => 'a-new-strong-password',
        'password_confirmation' => 'a-new-strong-password',
    ])->assertRedirect(route('children.edit', $child));

    expect(Hash::check('a-new-strong-password', $child->fresh()->password))->toBeTrue();
});

it('hides other families\' children behind a 404', function (string $method, string $route) {
    $child = User::factory()->student()->create(['name' => 'Not Yours']);
    User::factory()->guardian()->create()->linkChild($child);

    $this->actingAs(User::factory()->guardian()->create())
        ->{$method}(route($route, $child), ['name' => 'Hacked', 'password' => 'x-strong-password-1', 'password_confirmation' => 'x-strong-password-1'])
        ->assertNotFound();

    expect($child->fresh()->name)->toBe('Not Yours');
})->with([
    'edit' => ['get', 'children.edit'],
    'update' => ['put', 'children.update'],
    'password' => ['put', 'children.password.update'],
]);

it('is only for guardians', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('children.index'))
        ->assertForbidden();
})->with(['tutor', 'student', 'admin']);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/ChildManagementTest.php`
Expected: FAIL with `Route [children.index] not defined`.

- [ ] **Step 3: Scoped binding and routes**

In `app/Providers/AppServiceProvider.php` `boot()` (add `use App\Models\User;` and `use Illuminate\Support\Facades\Route;`):

```php
Route::bind('child', function (string $value): User {
    /** @var User $guardian */
    $guardian = request()->user();

    return $guardian->children()->whereKey($value)->firstOrFail();
});
```

`auth` runs before `SubstituteBindings` (Laravel's middleware priority), so `request()->user()` is set. `firstOrFail()` gives a 404 for anyone else's child. `role:guardian` isn't in the priority list, so it runs _after_ binding. A non-guardian hitting a `{child}` URL therefore gets a 404, which is acceptable, while `children.index` (no binding) gives a 403.

In `routes/web.php`, add `use App\Http\Controllers\ChildController;` and `use App\Http\Controllers\ChildPasswordController;`:

```php
Route::middleware(['auth', 'verified', 'role:guardian'])->group(function () {
    Route::resource('children', ChildController::class)->except(['show', 'destroy']);
    Route::put('children/{child}/password', ChildPasswordController::class)->name('children.password.update');
});
```

- [ ] **Step 4: Form requests**

`php artisan make:request StoreChildRequest --no-interaction` (and the same for `UpdateChildRequest` and `UpdatePasswordRequest`).

`app/Http/Requests/StoreChildRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Concerns\AccountValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreChildRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Route middleware (`role:guardian`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseAccountInput();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(required: false),
            'password' => $this->passwordRules(),
            'year_group' => $this->yearGroupRules(),
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'relationship' => ['nullable', 'string', 'max:50'],
        ];
    }
}
```

`app/Http/Requests/UpdateChildRequest.php`: the same class shape, with these `rules()`:

```php
/** @var \App\Models\User $child */
$child = $this->route('child');

return [
    'name' => ['required', 'string', 'max:255'],
    'username' => $this->usernameRules(ignore: $child),
    'email' => $this->emailRules(required: false, ignore: $child),
    'year_group' => $this->yearGroupRules(),
];
```

`app/Http/Requests/UpdatePasswordRequest.php`: uses `AccountValidationRules` too, with `authorize()` returning true (comment: "Route middleware and bindings already scope who can reach this.") and:

```php
public function rules(): array
{
    return ['password' => $this->passwordRules()];
}
```

(No `prepareForValidation`.)

- [ ] **Step 5: Controllers**

`php artisan make:controller ChildController --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\CreateStudentAccount;
use App\Enums\YearGroup;
use App\Http\Requests\StoreChildRequest;
use App\Http\Requests\UpdateChildRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChildController extends Controller
{
    public function index(Request $request): Response
    {
        $children = $request->user()->children()
            ->with('studentProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $child): array => [
                'id' => $child->id,
                'name' => $child->name,
                'username' => $child->username,
                'email' => $child->email,
                'yearGroup' => $child->studentProfile?->year_group->label(),
            ]);

        return Inertia::render('children/index', ['children' => $children]);
    }

    public function create(): Response
    {
        return Inertia::render('children/create', ['yearGroups' => YearGroup::options()]);
    }

    public function store(StoreChildRequest $request, CreateStudentAccount $createStudent): RedirectResponse
    {
        $child = $createStudent->handle(
            $request->safe()->only(['name', 'username', 'email', 'password', 'year_group', 'date_of_birth']),
            $request->user(),
            $request->validated('relationship'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$child->name} has been added."]);

        return to_route('children.index');
    }

    public function edit(User $child): Response
    {
        return Inertia::render('children/edit', [
            'child' => [
                'id' => $child->id,
                'name' => $child->name,
                'username' => $child->username,
                'email' => $child->email,
                'yearGroup' => $child->studentProfile?->year_group->value,
            ],
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function update(UpdateChildRequest $request, User $child): RedirectResponse
    {
        $child->fill($request->safe()->only(['name', 'email']));
        $child->forceFill(['username' => $request->validated('username')])->save();
        $child->studentProfile()->update(['year_group' => (int) $request->validated('year_group')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$child->name}'s details have been saved."]);

        return to_route('children.index');
    }
}
```

`php artisan make:controller ChildPasswordController --invokable --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class ChildPasswordController extends Controller
{
    /**
     * Set a new password for one of the guardian's children.
     */
    public function __invoke(UpdatePasswordRequest $request, User $child): RedirectResponse
    {
        $child->forceFill(['password' => Hash::make($request->validated('password'))])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$child->name}'s password has been changed."]);

        return to_route('children.edit', $child);
    }
}
```

**Edge case:** setting `email` to blank on update stores `null` (via `normaliseAccountInput`). If the email changes to a new non-null address, keep `email_verified_at` as it is: the guardian is trusted (Global Constraints).

- [ ] **Step 6: Frontend types and shared components**

`resources/js/types/ui.ts`:

```ts
export type Option = { value: number | string; label: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Toast = { type: 'success' | 'error'; message: string };
```

In `resources/js/types/index.ts`, add `export type * from './ui';`.

In `resources/js/types/global.d.ts`, inside `interface InertiaConfig`, add:

```ts
flashDataType: {
    toast?: import('@/types/ui').Toast;
};
```

`resources/js/components/ui/select.tsx`:

```tsx
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Select({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'h-10 w-full rounded-md border border-input bg-surface px-3 text-sm text-foreground disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            {...props}
        />
    );
}
```

`resources/js/components/toast.tsx`:

```tsx
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export default function Toast() {
    const { flash } = usePage();

    if (!flash.toast) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'rounded-md px-4 py-3 text-sm font-medium',
                flash.toast.type === 'success'
                    ? 'bg-present-soft text-present'
                    : 'bg-absent-soft text-absent',
            )}
        >
            {flash.toast.message}
        </div>
    );
}
```

`resources/js/components/student-fields.tsx`:

```tsx
import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

type StudentFieldsProps = {
    errors: Record<string, string | undefined>;
    yearGroups: Option[];
    defaults?: {
        name?: string;
        username?: string | null;
        email?: string | null;
        yearGroup?: number | null;
    };
};

export default function StudentFields({
    errors,
    yearGroups,
    defaults = {},
}: StudentFieldsProps) {
    return (
        <>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="name">Full name</Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={defaults.name}
                    required
                    aria-invalid={!!errors.name}
                    aria-describedby="name-error"
                />
                <FieldError id="name-error" message={errors.name} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="username">Username</Label>
                <Input
                    id="username"
                    name="username"
                    defaultValue={defaults.username ?? ''}
                    autoComplete="off"
                    required
                    aria-invalid={!!errors.username}
                    aria-describedby="username-hint username-error"
                />
                <p id="username-hint" className="text-xs text-muted-foreground">
                    Lowercase letters, numbers, dots, dashes or underscores, for
                    example ava.okafor
                </p>
                <FieldError id="username-error" message={errors.username} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="email">Email (optional)</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    defaultValue={defaults.email ?? ''}
                    aria-invalid={!!errors.email}
                    aria-describedby="email-error"
                />
                <FieldError id="email-error" message={errors.email} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="year_group">Year group</Label>
                <Select
                    id="year_group"
                    name="year_group"
                    defaultValue={defaults.yearGroup ?? ''}
                    required
                    aria-invalid={!!errors.year_group}
                    aria-describedby="year_group-error"
                >
                    <option value="" disabled>
                        Choose a year group
                    </option>
                    {yearGroups.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
                <FieldError id="year_group-error" message={errors.year_group} />
            </div>
        </>
    );
}
```

Also give `resources/js/components/field-error.tsx`'s `<p>` the attribute `role="alert"` (2a a11y minor).

- [ ] **Step 7: Layout nav and toast**

In `resources/js/layouts/app-layout.tsx`:

- import `Toast from '@/components/toast'`, `{ index as childrenIndex } from '@/routes/children'` and `type { Role } from '@/types'` (already imported).
- add a nav map:

```tsx
const navItems: Record<Role, { label: string; href: () => { url: string } }[]> =
    {
        admin: [{ label: 'Dashboard', href: dashboard }],
        tutor: [{ label: 'Today', href: dashboard }],
        student: [{ label: 'Dashboard', href: dashboard }],
        guardian: [
            { label: 'Dashboard', href: dashboard },
            { label: 'Children', href: childrenIndex },
        ],
    };
```

- render, after the logo `Link` and inside the header row, when `auth.user` is set:

```tsx
<nav className="flex items-center gap-4" aria-label="Main">
    {navItems[auth.user.role].map((item) => (
        <Link
            key={item.label}
            href={item.href()}
            className="text-sm font-medium text-foreground hover:text-primary"
        >
            {item.label}
        </Link>
    ))}
</nav>
```

(Task 4 adds the admin `Users` item.)

- render `<Toast />` as the first child of `<main>`, before the `<h1>`.

If the `href` type above doesn't satisfy `tsc`, type it as `href: () => import('@/wayfinder').RouteDefinition<'get'>` instead and ledger it.

- [ ] **Step 8: Pages**

`resources/js/pages/children/index.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { create, edit } from '@/routes/children';

type Child = {
    id: number;
    name: string;
    username: string | null;
    email: string | null;
    yearGroup: string | null;
};

export default function ChildrenIndex({ children }: { children: Child[] }) {
    return (
        <AppLayout title="Your children">
            <div>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                >
                    Add a child
                </Link>
            </div>
            {children.length === 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle>No children added yet</CardTitle>
                        <CardDescription>
                            Add your child to create their login. They'll use
                            their username and password to sign in.
                        </CardDescription>
                    </CardHeader>
                    <CardContent />
                </Card>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2">
                    {children.map((child) => (
                        <li key={child.id}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>{child.name}</CardTitle>
                                    <CardDescription>
                                        Username:{' '}
                                        <span className="font-medium text-foreground">
                                            {child.username}
                                        </span>
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex items-center justify-between">
                                    {child.yearGroup && (
                                        <Badge tone="brand">
                                            {child.yearGroup}
                                        </Badge>
                                    )}
                                    <Link
                                        href={edit(child.id)}
                                        className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        Edit
                                    </Link>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
```

`resources/js/pages/children/create.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/children';
import type { Option } from '@/types';

export default function CreateChild({ yearGroups }: { yearGroups: Option[] }) {
    return (
        <AppLayout title="Add a child">
            <Card className="max-w-xl">
                <CardContent>
                    <Form
                        {...store.form()}
                        resetOnSuccess
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <StudentFields
                                    errors={errors}
                                    yearGroups={yearGroups}
                                />
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="relationship">
                                        Your relationship (optional)
                                    </Label>
                                    <Input
                                        id="relationship"
                                        name="relationship"
                                        placeholder="e.g. Mother"
                                        aria-invalid={!!errors.relationship}
                                        aria-describedby="relationship-error"
                                    />
                                    <FieldError
                                        id="relationship-error"
                                        message={errors.relationship}
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password">
                                        Password for your child
                                    </Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        aria-invalid={!!errors.password}
                                        aria-describedby="password-error"
                                    />
                                    <FieldError
                                        id="password-error"
                                        message={errors.password}
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? 'Adding…' : 'Add child'}
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

`resources/js/pages/children/edit.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/children';
import { update as updatePassword } from '@/routes/children/password';
import type { Option } from '@/types';

type EditChildProps = {
    child: {
        id: number;
        name: string;
        username: string | null;
        email: string | null;
        yearGroup: number | null;
    };
    yearGroups: Option[];
};

export default function EditChild({ child, yearGroups }: EditChildProps) {
    return (
        <AppLayout title={`Edit ${child.name}`}>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form(child.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <StudentFields
                                        errors={errors}
                                        yearGroups={yearGroups}
                                        defaults={child}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        Save details
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Change password</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...updatePassword.form(child.id)}
                            resetOnSuccess
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="new_password">
                                            New password
                                        </Label>
                                        <Input
                                            id="new_password"
                                            name="password"
                                            type="password"
                                            autoComplete="new-password"
                                            required
                                            aria-invalid={!!errors.password}
                                            aria-describedby="new_password-error"
                                        />
                                        <FieldError
                                            id="new_password-error"
                                            message={errors.password}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="new_password_confirmation">
                                            Confirm new password
                                        </Label>
                                        <Input
                                            id="new_password_confirmation"
                                            name="password_confirmation"
                                            type="password"
                                            autoComplete="new-password"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Change password
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

In `resources/js/pages/dashboard/guardian.tsx`, replace `<CardContent />` with a link to the children list:

```tsx
<CardContent>
    <Link
        href={childrenIndex()}
        className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
    >
        Manage children
    </Link>
</CardContent>
```

(imports: `import { Link } from '@inertiajs/react';` and `import { index as childrenIndex } from '@/routes/children';`). Change the description to: `"Add your children so they can log in, and soon see their attendance and quiz progress here."`

- [ ] **Step 9: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/ChildManagementTest.php
```

Expected: PASS (1 + 1 + 1 + 4 + 1 + 1 + 1 + 1 + 3 + 3 = 17).

- [ ] **Step 10: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
php artisan test --compact
git add -A && git commit -m "feat: guardians add and manage their children"
```

---

### Task 4: Admin user directory, create and edit

**Files:**

- Create: `app/Http/Controllers/Admin/UserController.php`, `app/Http/Requests/Admin/StoreUserRequest.php`, `app/Http/Requests/Admin/UpdateUserRequest.php`, `resources/js/pages/admin/users/{index,create,edit}.tsx`
- Modify: `routes/web.php`, `resources/js/layouts/app-layout.tsx`, `resources/js/pages/dashboard/admin.tsx`
- Test: `tests/Feature/Admin/UserManagementTest.php`

**Interfaces:**

- Consumes: `AccountValidationRules`, `CreateStudentAccount`, `YearGroup::options()`, `Role`, `Select`, `StudentFields`, `Toast`, `Paginated<T>`.
- Produces: routes `admin.users.index|create|store|edit|update` (prefix `/admin`, middleware `auth`, `verified`, `role:admin`), with `edit` bound `withTrashed`. Index query params: `role` (one of the `Role` values), `search`, `status` (`active` | `deactivated`).

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Admin/UserManagementTest --no-interaction`:

```php
<?php

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Admin Adaeze']);
});

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('admin.users.index'))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists users with their role', function () {
    User::factory()->tutor()->create(['name' => 'Tunde Tutor']);

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/index')
            ->has('users.data', 2)
            ->where('users.data.0.name', 'Admin Adaeze')
            ->where('users.data.1.role', 'tutor'));
});

it('filters by role and searches by name, email or username', function () {
    User::factory()->tutor()->create(['name' => 'Tunde Tutor']);
    User::factory()->student()->create(['name' => 'Ava', 'username' => 'ava.okafor']);
    User::factory()->guardian()->create(['name' => 'Ngozi', 'email' => 'ngozi@example.com']);

    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'tutor']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Tunde Tutor'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['search' => 'ava.ok']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Ava'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['search' => 'NGOZI@']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Ngozi'));
});

it('ignores an unknown role filter instead of erroring', function () {
    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'superuser']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

it('shows deactivated users only when asked', function () {
    User::factory()->tutor()->create(['name' => 'Gone'])->delete();

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['status' => 'deactivated']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Gone'));
});

it('creates a tutor who can log in straight away', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'role' => 'tutor',
        'name' => 'Tunde Tutor',
        'email' => 'Tunde@Academy.test',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect(route('admin.users.index'))
        ->assertInertiaFlash('toast.type', 'success');

    $tutor = User::firstWhere('email', 'tunde@academy.test');
    expect($tutor->role)->toBe(Role::Tutor)->and($tutor->hasVerifiedEmail())->toBeTrue();

    auth()->logout();
    $this->post(route('login.store'), ['login' => 'tunde@academy.test', 'password' => 'a-strong-password'])
        ->assertRedirect('/dashboard');
});

it('creates a student with a username and year group and no email', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'role' => 'student',
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'email' => '',
        'year_group' => 7,
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect(route('admin.users.index'));

    $student = User::firstWhere('username', 'ava.okafor');
    expect($student->role)->toBe(Role::Student)
        ->and($student->email)->toBeNull()
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year7);
});

it('requires an email for staff and a username and year group for students', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'role' => 'tutor', 'name' => 'No Email', 'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');

    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'role' => 'student', 'name' => 'No Username', 'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors(['username', 'year_group']);
});

it('rejects an unknown role on create', function () {
    $this->actingAs($this->admin)->post(route('admin.users.store'), [
        'role' => 'superuser', 'name' => 'X', 'email' => 'x@example.com', 'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('role');
});

it('edits a student but never changes a role', function () {
    $student = User::factory()->student(YearGroup::Year7)->create(['username' => 'ava.okafor']);

    $this->actingAs($this->admin)->get(route('admin.users.edit', $student))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/edit')
            ->where('user.role', 'student')
            ->where('user.yearGroup', 7));

    $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
        'name' => 'Ava O.',
        'username' => 'ava.okafor',
        'email' => 'Ava@Example.com',
        'year_group' => 8,
        'role' => 'admin',
    ])->assertRedirect(route('admin.users.index'));

    $student->refresh();
    expect($student->name)->toBe('Ava O.')
        ->and($student->email)->toBe('ava@example.com')
        ->and($student->role)->toBe(Role::Student)
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year8);
});

it('edits a tutor\'s email, rejecting one that is taken', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $tutor = User::factory()->tutor()->create();

    $this->actingAs($this->admin)->put(route('admin.users.update', $tutor), [
        'name' => $tutor->name,
        'email' => 'TAKEN@example.com',
    ])->assertSessionHasErrors('email');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/UserManagementTest.php`
Expected: FAIL with `Route [admin.users.index] not defined`.

- [ ] **Step 3: Routes**

In `routes/web.php`, add `use App\Http\Controllers\Admin\UserController;`:

```php
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show', 'destroy'])->withTrashed(['edit']);
});
```

- [ ] **Step 4: Form requests**

`php artisan make:request Admin/StoreUserRequest --no-interaction`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AccountValidationRules;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseAccountInput();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isStudent = $this->input('role') === Role::Student->value;

        return [
            'role' => ['required', Rule::enum(Role::class)],
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules(required: ! $isStudent),
            'username' => $isStudent ? $this->usernameRules() : ['exclude'],
            'year_group' => $isStudent ? $this->yearGroupRules() : ['exclude'],
            'password' => $this->passwordRules(),
        ];
    }
}
```

`php artisan make:request Admin/UpdateUserRequest --no-interaction`: the same shape, but `rules()` reads the bound user and **has no `role` rule**:

```php
/** @var \App\Models\User $user */
$user = $this->route('user');
$isStudent = $user->hasRole(Role::Student);

return [
    'name' => ['required', 'string', 'max:255'],
    'email' => $this->emailRules(required: ! $isStudent, ignore: $user),
    'username' => $isStudent ? $this->usernameRules(ignore: $user) : ['exclude'],
    'year_group' => $isStudent ? $this->yearGroupRules() : ['exclude'],
];
```

- [ ] **Step 5: Controller**

`php artisan make:controller Admin/UserController --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateStudentAccount;
use App\Enums\Role;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $role = Role::tryFrom((string) $request->query('role'));
        $search = Str::lower(trim((string) $request->query('search')));
        $deactivated = $request->query('status') === 'deactivated';

        $users = User::query()
            ->when($deactivated, fn (Builder $query) => $query->onlyTrashed())
            ->when($role, fn (Builder $query) => $query->where('role', $role))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")))
            ->with('studentProfile')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'role' => $user->role->value,
                'yearGroup' => $user->studentProfile?->year_group->label(),
                'deactivated' => $user->trashed(),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => [
                'role' => $role?->value,
                'search' => $search,
                'status' => $deactivated ? 'deactivated' : 'active',
            ],
            'roles' => self::roleOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/create', [
            'roles' => self::roleOptions(),
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function store(StoreUserRequest $request, CreateStudentAccount $createStudent): RedirectResponse
    {
        $role = $request->enum('role', Role::class);

        if ($role === Role::Student) {
            $user = $createStudent->handle($request->safe()->only(['name', 'username', 'email', 'password', 'year_group']));
        } else {
            $user = new User([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
            ]);
            $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been created."]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'role' => $user->role->value,
                'yearGroup' => $user->studentProfile?->year_group->value,
                'deactivated' => $user->trashed(),
            ],
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email']));

        if ($user->hasRole(Role::Student)) {
            $user->forceFill(['username' => $request->validated('username')]);
            $user->studentProfile()->update(['year_group' => (int) $request->validated('year_group')]);
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been updated."]);

        return to_route('admin.users.index');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function roleOptions(): array
    {
        return [
            ['value' => Role::Student->value, 'label' => 'Student'],
            ['value' => Role::Guardian->value, 'label' => 'Parent / guardian'],
            ['value' => Role::Tutor->value, 'label' => 'Tutor'],
            ['value' => Role::Admin->value, 'label' => 'Admin'],
        ];
    }
}
```

The search is lowercased, and emails and usernames are stored lowercase, so `like` matches regardless of case. `LOWER(name)` covers mixed-case names on SQLite, MySQL and Postgres alike. The test `lists users with their role` relies on `orderBy('name')`: "Admin Adaeze" sorts before "Tunde Tutor".

- [ ] **Step 6: Pages and nav**

In `app-layout.tsx` `navItems.admin`, add `{ label: 'Users', href: adminUsersIndex }`, with `import { index as adminUsersIndex } from '@/routes/admin/users';`.

In `resources/js/pages/dashboard/admin.tsx`, replace `<CardContent />` with a "Manage users" link to `adminUsersIndex()`, styled like the guardian dashboard's link. Change the card title to `"Users"` and the description to `"Create tutor, student and parent accounts, and deactivate ones that are no longer needed."`

`resources/js/pages/admin/users/index.tsx`:

```tsx
import { Form, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { create, edit, index } from '@/routes/admin/users';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    role: string;
    yearGroup: string | null;
    deactivated: boolean;
};

type UsersIndexProps = {
    users: Paginated<Row>;
    filters: {
        role: string | null;
        search: string;
        status: 'active' | 'deactivated';
    };
    roles: Option[];
};

export default function UsersIndex({ users, filters, roles }: UsersIndexProps) {
    const roleLabel = (value: string) =>
        roles.find((role) => role.value === value)?.label ?? value;

    return (
        <AppLayout title="Users">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <Form
                    {...index.form()}
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name, email or username"
                            className="w-64"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="role">Role</Label>
                        <Select
                            id="role"
                            name="role"
                            defaultValue={filters.role ?? ''}
                            className="w-44"
                        >
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="status">Status</Label>
                        <Select
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            className="w-40"
                        >
                            <option value="active">Active</option>
                            <option value="deactivated">Deactivated</option>
                        </Select>
                    </div>
                    <Button type="submit" variant="outline">
                        Filter
                    </Button>
                </Form>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                >
                    Add user
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
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Login
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Role
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
                            {users.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No users match these filters.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr
                                    key={user.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-3 font-medium">
                                        {user.name}
                                        {user.deactivated && (
                                            <Badge
                                                tone="absent"
                                                className="ml-2"
                                            >
                                                Deactivated
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {user.username ?? user.email}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge tone="brand">
                                            {roleLabel(user.role)}
                                        </Badge>
                                        {user.yearGroup && (
                                            <span className="ml-2 text-muted-foreground">
                                                {user.yearGroup}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={edit(user.id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
            <nav
                className="flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <span className="text-muted-foreground">
                    {users.total} users · page {users.current_page} of{' '}
                    {users.last_page}
                </span>
                <div className="flex gap-3">
                    {users.prev_page_url && (
                        <Link
                            href={users.prev_page_url}
                            preserveState
                            className="font-medium text-primary"
                        >
                            Previous
                        </Link>
                    )}
                    {users.next_page_url && (
                        <Link
                            href={users.next_page_url}
                            preserveState
                            className="font-medium text-primary"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
```

If `index.form()` doesn't produce a GET form, or `<Form>` rejects `options`, fall back to `<Form action={index.url()} method="get">` and ledger it.

`resources/js/pages/admin/users/create.tsx`: role select first; student fields when the role is student, otherwise name and email:

```tsx
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/admin/users';
import type { Option } from '@/types';

export default function CreateUser({
    roles,
    yearGroups,
}: {
    roles: Option[];
    yearGroups: Option[];
}) {
    const [role, setRole] = useState('student');

    return (
        <AppLayout title="Add user">
            <Card className="max-w-xl">
                <CardContent>
                    <Form {...store.form()} className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="role">Role</Label>
                                    <Select
                                        id="role"
                                        name="role"
                                        value={role}
                                        onChange={(event) =>
                                            setRole(event.target.value)
                                        }
                                        aria-invalid={!!errors.role}
                                        aria-describedby="role-error"
                                    >
                                        {roles.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                    <FieldError
                                        id="role-error"
                                        message={errors.role}
                                    />
                                </div>
                                {role === 'student' ? (
                                    <StudentFields
                                        errors={errors}
                                        yearGroups={yearGroups}
                                    />
                                ) : (
                                    <>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="name">
                                                Full name
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                aria-invalid={!!errors.name}
                                                aria-describedby="name-error"
                                            />
                                            <FieldError
                                                id="name-error"
                                                message={errors.name}
                                            />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="email">Email</Label>
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                required
                                                aria-invalid={!!errors.email}
                                                aria-describedby="email-error"
                                            />
                                            <FieldError
                                                id="email-error"
                                                message={errors.email}
                                            />
                                        </div>
                                    </>
                                )}
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        aria-invalid={!!errors.password}
                                        aria-describedby="password-error"
                                    />
                                    <FieldError
                                        id="password-error"
                                        message={errors.password}
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? 'Creating…' : 'Create user'}
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

`resources/js/pages/admin/users/edit.tsx`, the details card only (Task 5 adds password, deactivate and restore):

```tsx
import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/admin/users';
import type { Option } from '@/types';

export type EditableUser = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    role: string;
    yearGroup: number | null;
    deactivated: boolean;
};

export default function EditUser({
    user,
    yearGroups,
}: {
    user: EditableUser;
    yearGroups: Option[];
}) {
    return (
        <AppLayout title={`Edit ${user.name}`}>
            {user.deactivated && (
                <Badge tone="absent">This account is deactivated</Badge>
            )}
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form(user.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {user.role === 'student' ? (
                                        <StudentFields
                                            errors={errors}
                                            yearGroups={yearGroups}
                                            defaults={user}
                                        />
                                    ) : (
                                        <>
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor="name">
                                                    Full name
                                                </Label>
                                                <Input
                                                    id="name"
                                                    name="name"
                                                    defaultValue={user.name}
                                                    required
                                                    aria-invalid={!!errors.name}
                                                    aria-describedby="name-error"
                                                />
                                                <FieldError
                                                    id="name-error"
                                                    message={errors.name}
                                                />
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor="email">
                                                    Email
                                                </Label>
                                                <Input
                                                    id="email"
                                                    name="email"
                                                    type="email"
                                                    defaultValue={
                                                        user.email ?? ''
                                                    }
                                                    required
                                                    aria-invalid={
                                                        !!errors.email
                                                    }
                                                    aria-describedby="email-error"
                                                />
                                                <FieldError
                                                    id="email-error"
                                                    message={errors.email}
                                                />
                                            </div>
                                        </>
                                    )}
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || user.deactivated
                                        }
                                    >
                                        Save details
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

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Admin/UserManagementTest.php
```

Expected: PASS (3 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 = 13).

- [ ] **Step 8: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
php artisan test --compact
git add -A && git commit -m "feat: admin user directory with create and edit"
```

---

### Task 5: Admin deactivate, restore and password reset

**Files:**

- Create: `app/Http/Controllers/Admin/RestoreUserController.php`, `app/Http/Controllers/Admin/UserPasswordController.php`
- Modify: `routes/web.php`, `app/Http/Controllers/Admin/UserController.php` (add `destroy`), `resources/js/pages/admin/users/edit.tsx`
- Test: `tests/Feature/Admin/UserLifecycleTest.php`

**Interfaces:**

- Consumes: `UpdatePasswordRequest` (Task 3), `SoftDeletes` (Task 1), the admin routes (Task 4).
- Produces: routes `admin.users.destroy` (`DELETE`), `admin.users.restore` (`POST /admin/users/{user}/restore`, `withTrashed`) and `admin.users.password.update` (`PUT /admin/users/{user}/password`).

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Admin/UserLifecycleTest --no-interaction`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('deactivates a user, who then cannot log in', function () {
    $tutor = User::factory()->tutor()->create(['email' => 'tunde@academy.test']);

    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $tutor))
        ->assertRedirect(route('admin.users.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($tutor->fresh()->trashed())->toBeTrue();

    auth()->logout();
    $this->post(route('login.store'), ['login' => 'tunde@academy.test', 'password' => 'password'])
        ->assertSessionHasErrors('login');
});

it('will not let an admin deactivate themselves', function () {
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect($this->admin->fresh()->trashed())->toBeFalse();
});

it('restores a deactivated user', function () {
    $tutor = User::factory()->tutor()->create();
    $tutor->delete();

    $this->actingAs($this->admin)->post(route('admin.users.restore', $tutor))
        ->assertRedirect(route('admin.users.edit', $tutor));

    expect($tutor->fresh()->trashed())->toBeFalse();
});

it('opens the edit page for a deactivated user', function () {
    $tutor = User::factory()->tutor()->create();
    $tutor->delete();

    $this->actingAs($this->admin)->get(route('admin.users.edit', $tutor))->assertOk();
});

it('sets a new password for any user', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($this->admin)->put(route('admin.users.password.update', $student), [
        'password' => 'a-new-strong-password',
        'password_confirmation' => 'a-new-strong-password',
    ])->assertRedirect(route('admin.users.edit', $student));

    expect(Hash::check('a-new-strong-password', $student->fresh()->password))->toBeTrue();
});

it('keeps lifecycle actions admin-only', function (string $method, string $route) {
    $target = User::factory()->student()->create();

    $this->actingAs(User::factory()->tutor()->create())
        ->{$method}(route($route, $target), ['password' => 'a-new-strong-password', 'password_confirmation' => 'a-new-strong-password'])
        ->assertForbidden();

    expect($target->fresh()->trashed())->toBeFalse();
})->with([
    'deactivate' => ['delete', 'admin.users.destroy'],
    'restore' => ['post', 'admin.users.restore'],
    'password' => ['put', 'admin.users.password.update'],
]);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/UserLifecycleTest.php`
Expected: FAIL with `Route [admin.users.destroy] not defined`.

- [ ] **Step 3: Routes**

In the admin group in `routes/web.php`, change the resource to `->except(['show'])` (so `destroy` is now included) and add:

```php
Route::post('users/{user}/restore', RestoreUserController::class)->withTrashed()->name('users.restore');
Route::put('users/{user}/password', UserPasswordController::class)->name('users.password.update');
```

with `use App\Http\Controllers\Admin\RestoreUserController;` and `use App\Http\Controllers\Admin\UserPasswordController;`.

- [ ] **Step 4: Controllers**

Add to `Admin/UserController`:

```php
public function destroy(Request $request, User $user): RedirectResponse
{
    if ($user->is($request->user())) {
        Inertia::flash('toast', ['type' => 'error', 'message' => "You can't deactivate your own account."]);

        return back();
    }

    $user->delete();

    Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been deactivated."]);

    return to_route('admin.users.index');
}
```

`php artisan make:controller Admin/RestoreUserController --invokable --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RestoreUserController extends Controller
{
    /**
     * Reactivate a deactivated account.
     */
    public function __invoke(User $user): RedirectResponse
    {
        $user->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been reactivated."]);

        return to_route('admin.users.edit', $user);
    }
}
```

`php artisan make:controller Admin/UserPasswordController --invokable --no-interaction`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserPasswordController extends Controller
{
    /**
     * Set a new password for any account.
     */
    public function __invoke(UpdatePasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill(['password' => Hash::make($request->validated('password'))])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name}'s password has been changed."]);

        return to_route('admin.users.edit', $user);
    }
}
```

- [ ] **Step 5: Edit page actions**

In `resources/js/pages/admin/users/edit.tsx`:

- import `{ Link }` as well as `Form`; `{ destroy, restore }` from `@/routes/admin/users`; and `{ update as updatePassword }` from `@/routes/admin/users/password`.
- add a second card inside the grid:

```tsx
<Card>
    <CardHeader>
        <CardTitle>Account</CardTitle>
    </CardHeader>
    <CardContent className="flex flex-col gap-6">
        {!user.deactivated && (
            <Form
                {...updatePassword.form(user.id)}
                resetOnSuccess
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="new_password">New password</Label>
                            <Input
                                id="new_password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                required
                                aria-invalid={!!errors.password}
                                aria-describedby="new_password-error"
                            />
                            <FieldError
                                id="new_password-error"
                                message={errors.password}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="new_password_confirmation">
                                Confirm new password
                            </Label>
                            <Input
                                id="new_password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Set password
                        </Button>
                    </>
                )}
            </Form>
        )}
        {user.deactivated ? (
            <Link
                href={restore(user.id)}
                as="button"
                className="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
            >
                Reactivate account
            </Link>
        ) : (
            <Link
                href={destroy(user.id)}
                as="button"
                onBefore={() =>
                    confirm(
                        `Deactivate ${user.name}? They won't be able to log in until reactivated.`,
                    )
                }
                className="inline-flex h-10 items-center justify-center rounded-md bg-danger px-4 text-sm font-medium text-white"
            >
                Deactivate account
            </Link>
        )}
    </CardContent>
</Card>
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Admin
```

Expected: PASS (13 + 1 + 1 + 1 + 1 + 1 + 3 = 21).

- [ ] **Step 7: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
git add -A && git commit -m "feat: admin deactivate, restore and password reset"
```

---

### Task 6: Close the deferred 2a auth gaps

**Files:**

- Create: `app/Http/Responses/NeutralPasswordResetLinkResponse.php`, `resources/js/pages/auth/confirm-password.tsx`
- Modify: `app/Providers/FortifyServiceProvider.php`, `resources/js/pages/auth/{login,forgot-password,verify-email,reset-password}.tsx`
- Delete: `app/Actions/Fortify/UpdateUserProfileInformation.php`, `app/Actions/Fortify/UpdateUserPassword.php`
- Test: `tests/Feature/Auth/AuthHardeningTest.php`

**Interfaces:**

- Consumes: the Fortify provider (2a).
- Produces: a neutral forgot-password response for unknown emails; `throttle:register` (5/min per IP) on `POST /register`; page `auth/confirm-password`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Auth/AuthHardeningTest --no-interaction`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('does not reveal whether an email has an account', function () {
    Notification::fake();

    $unknown = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@example.com']);

    $unknown->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    User::factory()->create(['email' => 'parent@example.com']);
    $known = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'parent@example.com']);

    $known->assertSessionHas('status', __('passwords.sent'));
});

it('still validates the email format', function () {
    $this->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

it('rate-limits sign-ups from one address', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), ['name' => 'x']);
    }

    $this->post(route('register.store'), ['name' => 'x'])->assertTooManyRequests();
});

it('shows the confirm-password page instead of an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Auth/AuthHardeningTest.php`
Expected: FAIL. The unknown email gets an `email` error, the 6th sign-up isn't throttled, and confirm-password returns 500. `still validates the email format` may already pass; ledger it as a guard.

- [ ] **Step 3: Neutral reset-link response**

`app/Http/Responses/NeutralPasswordResetLinkResponse.php`:

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Answer an unknown email exactly like a known one, so the form can't be used
 * to find out who has an account. Throttling still shows its real error.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        if ($this->status === Password::INVALID_USER) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        throw ValidationException::withMessages([Fortify::email() => [__($this->status)]]);
    }
}
```

Check how Fortify resolves this contract (`grep -rn "FailedPasswordResetLinkRequestResponse" vendor/laravel/fortify/src`). Fortify does `app(FailedPasswordResetLinkRequestResponse::class, ['status' => $status])`, so bind the contract to this class in `FortifyServiceProvider::register()`:

```php
$this->app->bind(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
```

(add the matching `use` lines). `__(Password::RESET_LINK_SENT)` is `__('passwords.sent')`.

- [ ] **Step 4: Register throttle, confirm-password view, stale actions**

In `FortifyServiceProvider::boot()`:

```php
RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

$this->app->booted(function (): void {
    Route::getRoutes()->getByName('register.store')?->middleware('throttle:register');
});

Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
```

(imports: `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Support\Facades\RateLimiter`, `Illuminate\Support\Facades\Route`). If `getByName` returns null at that point (the route cache, or Fortify registering routes later), move the attachment into `bootstrap/app.php` `->booted(...)`, or verify with `php artisan route:list --name=register.store -v`, and ledger it.

Delete `app/Actions/Fortify/UpdateUserProfileInformation.php` and `app/Actions/Fortify/UpdateUserPassword.php`, and remove their `Fortify::updateUserProfileInformationUsing(...)` / `Fortify::updateUserPasswordsUsing(...)` lines and imports. Those features are off, and the profile action required an email and didn't lowercase.

- [ ] **Step 5: Confirm-password page and a11y**

`resources/js/pages/auth/confirm-password.tsx`:

```tsx
import { Form, Head } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <AuthLayout
            title="Confirm your password"
            description="For your security, please enter your password to continue."
        >
            <Head title="Confirm password" />
            <Form
                {...store.form()}
                resetOnSuccess
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                autoFocus
                                required
                                aria-invalid={!!errors.password}
                                aria-describedby="password-error"
                            />
                            <FieldError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>
                        <Button type="submit" size="lg" disabled={processing}>
                            Confirm
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

Check the generated Wayfinder path for `password.confirm.store` (`ls resources/js/routes/password`). If it's exported differently (e.g. `confirm.store` from `@/routes/password/confirm`), adjust the import and ledger it.

A11y fixes (2a minors):

- the status `<p>` banners in `login.tsx`, `forgot-password.tsx` and `verify-email.tsx` get `role="status"`;
- in `reset-password.tsx`, the read-only email `<Input>` gets `aria-invalid={!!errors.email}` and `aria-describedby="email-error"`.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Auth
```

Expected: PASS (all Auth tests: 20 from 2a, plus 4 = 24). The 2a test `emails a reset link` must still pass.

- [ ] **Step 7: Full verification and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check && npm run build
php artisan test --compact
php artisan migrate:fresh --seed --no-interaction
git add -A && git commit -m "fix: close deferred auth gaps from phase 2a"
```

Expected: the build succeeds and the full suite passes.

Smoke test over Herd:

- log in as `parent@academy.test`, then go to Children, add a child, edit them and change their password;
- log in as `admin@academy.test`, then go to Users, filter, add a tutor, deactivate them, restore them and set a password;
- confirm the deactivated tutor can't log in.
