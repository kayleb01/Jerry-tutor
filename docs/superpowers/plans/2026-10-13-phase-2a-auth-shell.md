# Phase 2a: Authentication & App Shell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let everyone sign in: guardians, tutors and admins with their email, and students with their username. Guardians can sign up, verify their email and reset their password. Every user lands on a dashboard for their role, inside a branded app shell.

**Architecture:** Fortify (headless) provides the auth routes and controllers. Its view callbacks render Inertia React pages built with the Phase 0 design system. A single `login` field accepts either an email or a username (`Fortify::authenticateUsing`). Registration only ever creates guardians. A single `/dashboard` route renders `dashboard/{role}`. An `EnsureUserHasRole` middleware (alias `role`) guards role-specific routes from Phase 2b onwards.

**Tech Stack:** Laravel 13, Fortify 1.41, Inertia v3 (`<Form>`, `<Link>`), React 19, Wayfinder, Tailwind v4, Pest 5.

**Spec:** [docs/design/design-brief.md](../../design/design-brief.md) §2, §4, §6 · [docs/architecture/database-schema.md](../../architecture/database-schema.md) §3 · [docs/plans/implementation-plan.md](../../plans/implementation-plan.md) Phase 2

## Global Constraints

- Approved decisions (2026-10-13): **Fortify** is the auth package; **students log in with a username and password**, and their email is optional; guardians, tutors and admins log in with email.
- **Self-registration creates guardians only.** `role` is not fillable (Phase 1 fix), so it's set explicitly with `forceFill`.
- Store usernames and emails lowercase. Fortify's `lowercase_usernames` lowercases the `login` input.
- Keep Fortify features to `registration()`, `resetPasswords()` and `emailVerification()`. 2FA, passkeys, profile update and password update are out of scope for 2a.
- UI copy uses UK English, with a friendly tone for students and families (brief §6). Every input has a `<Label>`, and errors are linked via `aria-describedby`.
- Use only design-system tokens and components (`Button`, `Card*`, `Input`, `Label`, `Badge`, `AppLogo`). Don't hard-code hex colours.
- Never hard-code URLs in TSX: use Wayfinder (`@/routes/...`). Run `php artisan wayfinder:generate --with-form` after route changes.
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `npm run check:fix` (for TS/TSX changes). Tests run with `php artisan test --compact <path>`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Mixed-case login identifiers**: `Ava.Okafor` or `Parent@Example.COM` must log in to the lowercase stored account. → Task 1 and Task 2 tests.
2. **Role injection at sign-up**: posting `role=admin` to `/register` must still create a guardian. → Task 2 test.
3. **Students without an email** must never be blocked by `verified` and never get sent a verification email. → Task 3 test.
4. **A signed-in user visiting `/login` or `/register`** is redirected to the dashboard and doesn't see the form again. → Task 4 test.
5. **Leaking user data in shared props**: `auth.user` must never contain `password`, `remember_token` or two-factor fields. → Task 4 test.

---

## File Structure

| File                                                                                       | Responsibility                                                                    |
| ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------- |
| `config/fortify.php`                                                                       | published by the install; `username => 'login'`, `home => '/dashboard'`, features |
| `app/Providers/FortifyServiceProvider.php`                                                 | auth lookup by email or username; Inertia view callbacks                          |
| `app/Actions/Fortify/CreateNewUser.php`                                                    | guardian-only registration                                                        |
| `database/migrations/*_add_username_and_phone_to_users_table.php`                          | `username` (unique, nullable), `phone`, nullable `email`                          |
| `app/Models/User.php`                                                                      | `MustVerifyEmail`; `hasVerifiedEmail()` exemption for students without an email   |
| `app/Http/Controllers/DashboardController.php`                                             | renders `dashboard/{role}`                                                        |
| `app/Http/Middleware/EnsureUserHasRole.php`                                                | `role:admin,tutor` gate                                                           |
| `app/Http/Middleware/HandleInertiaRequests.php`                                            | safe `auth.user` shape                                                            |
| `resources/js/layouts/auth-layout.tsx`                                                     | centred, branded card for auth pages                                              |
| `resources/js/layouts/app-layout.tsx`                                                      | top bar: logo, user, role badge, log out                                          |
| `resources/js/components/field-error.tsx`                                                  | accessible error message                                                          |
| `resources/js/pages/auth/{login,register,forgot-password,reset-password,verify-email}.tsx` | auth screens                                                                      |
| `resources/js/pages/dashboard/{admin,tutor,student,guardian}.tsx`                          | role landing pages (empty states for now)                                         |
| `resources/js/pages/welcome.tsx`                                                           | branded landing page with Log in / Sign up                                        |
| `resources/js/types/auth.ts`                                                               | `User` type including `role` and `username`                                       |

---

### Task 1: Install Fortify and log in with email or username

**Files:**

- Create: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/*` (all via `fortify:install`), `database/migrations/<ts>_add_username_and_phone_to_users_table.php`, `resources/js/layouts/auth-layout.tsx`, `resources/js/components/field-error.tsx`, `resources/js/pages/auth/login.tsx`
- Modify: `app/Models/User.php` (docblock only), `database/factories/UserFactory.php`
- Test: `tests/Feature/Auth/LoginTest.php`

**Interfaces:**

- Consumes: `User`, `Role`, and the factory states from Phase 1 (`student()`, `guardian()`, `tutor()`, `admin()`).
- Produces: `users.username` (nullable, unique) and `users.phone` (nullable); nullable `users.email`. Fortify username field `login`. Inertia page `auth/login` with props `{ status: string|null, canResetPassword: bool, canRegister: bool }`. Wayfinder: `login` from `@/routes`, `store` from `@/routes/login`. `AuthLayout({ title, description, children })`. `FieldError({ id, message })`.

- [ ] **Step 1: Install Fortify scaffolding** (generated code; the TDD exception for scaffolding)

```bash
php artisan fortify:install --no-interaction
git status --short
```

Expected: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/*.php` and new migrations in `database/migrations/` (two-factor columns, and possibly passkeys). `bootstrap/providers.php` now lists `FortifyServiceProvider`.

**Delete** the published two-factor and passkey migrations, since those features stay disabled (YAGNI). Record the deleted filenames in the ledger.

- [ ] **Step 2: Write the failing test**

`php artisan make:test --pest Auth/LoginTest --no-interaction`:

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canResetPassword', true)
            ->where('canRegister', true)
            ->where('status', null));
});

it('logs a guardian in with their email', function () {
    $guardian = User::factory()->guardian()->create(['email' => 'parent@example.com']);

    $this->post(route('login.store'), ['login' => 'parent@example.com', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($guardian);
});

it('logs a student in with their username, ignoring case', function () {
    $student = User::factory()->student()->create(['email' => null, 'username' => 'ava.okafor']);

    $this->post(route('login.store'), ['login' => 'Ava.Okafor', 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($student);
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'parent@example.com']);

    $this->from(route('login'))
        ->post(route('login.store'), ['login' => 'parent@example.com', 'password' => 'wrong-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('rejects an unknown username', function () {
    $this->post(route('login.store'), ['login' => 'nobody', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Auth/LoginTest.php`
Expected: FAIL. The login view isn't registered (the GET returns 404 or a missing-view error), and there's no `username` column.

- [ ] **Step 4: Migration**

`php artisan make:migration add_username_and_phone_to_users_table --table=users --no-interaction`:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('username')->nullable()->unique()->after('name');
        $table->string('phone')->nullable()->after('email');
        $table->string('email')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropUnique(['username']);
        $table->dropColumn(['username', 'phone']);
        $table->string('email')->nullable(false)->change();
    });
}
```

In `app/Models/User.php`, add `@property string|null $username` and `@property string|null $phone` to the docblock, and change `@property string $email` to `@property string|null $email`. **Don't** add `username` to `#[Fillable]` (admins set it explicitly in Phase 2b). Leave `phone` out of `#[Fillable]` here too; Task 2 adds it.

- [ ] **Step 5: Configure Fortify**

In `config/fortify.php`, set:

```php
'username' => 'login',
'email' => 'email',
'lowercase_usernames' => true,
'home' => '/dashboard',
'views' => true,
'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::emailVerification(),
],
```

Until Task 4 adds `/dashboard`, redirects to it will 404. That's fine, because the tests only assert the redirect target.

- [ ] **Step 6: The service provider**

Replace `boot()` in `app/Providers/FortifyServiceProvider.php`. Keep the stub's existing `use` lines for the actions, `Limit`, `RateLimiter` and `Str`. Remove `RedirectIfTwoFactorAuthenticatable` and the `two-factor`/`passkeys` limiters. Add `use App\Models\User;`, `use Illuminate\Support\Facades\Hash;`, `use Inertia\Inertia;` and `use Laravel\Fortify\Features;`.

```php
public function boot(): void
{
    Fortify::createUsersUsing(CreateNewUser::class);
    Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
    Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
    Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

    Fortify::authenticateUsing(function (Request $request): ?User {
        $login = (string) $request->input(Fortify::username());
        $column = str_contains($login, '@') ? 'email' : 'username';

        $user = User::query()->where($column, $login)->first();

        return $user && Hash::check((string) $request->input('password'), $user->password)
            ? $user
            : null;
    });

    Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
        'status' => $request->session()->get('status'),
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'canRegister' => Features::enabled(Features::registration()),
    ]));

    RateLimiter::for('login', function (Request $request) {
        $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

        return Limit::perMinute(5)->by($throttleKey);
    });
}
```

- [ ] **Step 7: Factory: usernames for students**

In `database/factories/UserFactory.php` `student()`, set a username as well as the role:

```php
return $this->state(fn (array $attributes) => [
    'role' => Role::Student,
    'username' => Str::lower(fake()->unique()->userName()),
])
```

(`Str` is already imported.) Leave the rest of `student()` (the `afterCreating`) unchanged.

- [ ] **Step 8: Shared UI pieces and the login page**

`resources/js/components/field-error.tsx`:

```tsx
type FieldErrorProps = {
    id: string;
    message?: string;
};

export default function FieldError({ id, message }: FieldErrorProps) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} className="text-sm text-danger">
            {message}
        </p>
    );
}
```

`resources/js/layouts/auth-layout.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

type AuthLayoutProps = {
    title: string;
    description?: string;
    children: ReactNode;
};

export default function AuthLayout({
    title,
    description,
    children,
}: AuthLayoutProps) {
    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-8 bg-background px-4 py-10">
            <Link href={home()} aria-label="Jerry Great Academy home">
                <AppLogo className="text-3xl" />
            </Link>
            <Card className="w-full max-w-md">
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                    {description && (
                        <CardDescription>{description}</CardDescription>
                    )}
                </CardHeader>
                <CardContent>{children}</CardContent>
            </Card>
        </main>
    );
}
```

`resources/js/pages/auth/login.tsx`:

```tsx
import { Form, Head, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type LoginProps = {
    status: string | null;
    canResetPassword: boolean;
    canRegister: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: LoginProps) {
    return (
        <AuthLayout
            title="Welcome back"
            description="Log in with your email, or your username if you're a student."
        >
            <Head title="Log in" />
            {status && (
                <p className="mb-4 rounded-md bg-present-soft px-3 py-2 text-sm text-present">
                    {status}
                </p>
            )}
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="login">Email or username</Label>
                            <Input
                                id="login"
                                name="login"
                                autoComplete="username"
                                autoFocus
                                required
                                aria-invalid={!!errors.login}
                                aria-describedby="login-error"
                            />
                            <FieldError
                                id="login-error"
                                message={errors.login}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && (
                                    <Link
                                        href={request()}
                                        className="text-sm text-primary underline-offset-4 hover:underline"
                                    >
                                        Forgot password?
                                    </Link>
                                )}
                            </div>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                aria-invalid={!!errors.password}
                                aria-describedby="password-error"
                            />
                            <FieldError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="remember"
                                className="size-4 accent-primary"
                            />
                            Keep me logged in
                        </label>
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? 'Logging in…' : 'Log in'}
                        </Button>
                        {canRegister && (
                            <p className="text-center text-sm text-muted-foreground">
                                New parent or guardian?{' '}
                                <Link
                                    href={register()}
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    Create an account
                                </Link>
                            </p>
                        )}
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

- [ ] **Step 9: Migrate, generate routes, run the tests to verify they pass**

```bash
php artisan migrate --no-interaction
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Auth/LoginTest.php
```

Expected: PASS (5 tests).

- [ ] **Step 10: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
php artisan test --compact
git add -A && git commit -m "feat: install Fortify with email or username login"
```

Expected: the full suite passes and `types:check` exits 0.

---

### Task 2: Guardian registration

**Files:**

- Modify: `app/Actions/Fortify/CreateNewUser.php`, `app/Providers/FortifyServiceProvider.php`, `app/Models/User.php` (`#[Fillable]` gains `phone`)
- Create: `resources/js/pages/auth/register.tsx`
- Test: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**

- Consumes: the Task 1 config and provider, and `AuthLayout` / `FieldError`.
- Produces: page `auth/register`; registration fields `name`, `email`, `phone` (optional), `password`, `password_confirmation`. The created user always has `Role::Guardian` and a lowercase email.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Auth/RegistrationTest --no-interaction`:

```php
<?php

use App\Enums\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the registration page', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/register'));
});

it('registers a guardian and logs them in', function () {
    $this->post(route('register.store'), [
        'name' => 'Ngozi Okafor',
        'email' => 'Ngozi@Example.COM',
        'phone' => '08031234567',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertRedirect('/dashboard');

    $user = User::firstWhere('email', 'ngozi@example.com');

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(Role::Guardian)
        ->and($user->phone)->toBe('08031234567');
    $this->assertAuthenticatedAs($user);
});

it('never lets a sign-up choose its own role', function () {
    $this->post(route('register.store'), [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'role' => 'admin',
    ]);

    expect(User::firstWhere('email', 'sneaky@example.com')->role)->toBe(Role::Guardian);
});

it('rejects an email that is already registered, ignoring case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Copycat',
        'email' => 'TAKEN@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ])->assertSessionHasErrors('email');
});

it('requires a name, email and confirmed password', function () {
    $this->post(route('register.store'), [
        'password' => 'a-strong-password',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors(['name', 'email', 'password']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Auth/RegistrationTest.php`
Expected: FAIL. `GET /register` has no view registered, and phone and role aren't handled.

- [ ] **Step 3: Guardian-only `CreateNewUser`**

Replace `create()` in `app/Actions/Fortify/CreateNewUser.php` (add `use App\Enums\Role;` and `use Illuminate\Support\Str;`):

```php
public function create(array $input): User
{
    $input['email'] = Str::lower((string) ($input['email'] ?? ''));

    Validator::make($input, [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
        'phone' => ['nullable', 'string', 'max:20'],
        'password' => $this->passwordRules(),
    ])->validate();

    $user = new User([
        'name' => $input['name'],
        'email' => $input['email'],
        'phone' => $input['phone'] ?? null,
        'password' => Hash::make($input['password']),
    ]);
    $user->forceFill(['role' => Role::Guardian])->save();

    return $user;
}
```

Add `'phone'` to `#[Fillable]` on `User`: `#[Fillable(['name', 'email', 'phone', 'password'])]`.

- [ ] **Step 4: Register the view**

In `FortifyServiceProvider::boot()`, after `loginView`:

```php
Fortify::registerView(fn () => Inertia::render('auth/register'));
```

- [ ] **Step 5: Registration page**

`resources/js/pages/auth/register.tsx`:

```tsx
import { Form, Head, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/register';

const fields = [
    {
        name: 'name',
        label: 'Your full name',
        type: 'text',
        autoComplete: 'name',
        required: true,
    },
    {
        name: 'email',
        label: 'Email address',
        type: 'email',
        autoComplete: 'email',
        required: true,
    },
    {
        name: 'phone',
        label: 'Phone number (optional)',
        type: 'tel',
        autoComplete: 'tel',
        required: false,
    },
    {
        name: 'password',
        label: 'Password',
        type: 'password',
        autoComplete: 'new-password',
        required: true,
    },
    {
        name: 'password_confirmation',
        label: 'Confirm password',
        type: 'password',
        autoComplete: 'new-password',
        required: true,
    },
] as const;

export default function Register() {
    return (
        <AuthLayout
            title="Create a parent account"
            description="You can add your children and subscribe once you're signed in."
        >
            <Head title="Create account" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        {fields.map((field) => (
                            <div
                                key={field.name}
                                className="flex flex-col gap-1.5"
                            >
                                <Label htmlFor={field.name}>
                                    {field.label}
                                </Label>
                                <Input
                                    id={field.name}
                                    name={field.name}
                                    type={field.type}
                                    autoComplete={field.autoComplete}
                                    required={field.required}
                                    aria-invalid={!!errors[field.name]}
                                    aria-describedby={`${field.name}-error`}
                                />
                                <FieldError
                                    id={`${field.name}-error`}
                                    message={errors[field.name]}
                                />
                            </div>
                        ))}
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing
                                ? 'Creating account…'
                                : 'Create account'}
                        </Button>
                        <p className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <Link
                                href={login()}
                                className="font-medium text-primary underline-offset-4 hover:underline"
                            >
                                Log in
                            </Link>
                        </p>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Auth/RegistrationTest.php
```

Expected: PASS (5 tests).

- [ ] **Step 7: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
git add -A && git commit -m "feat: guardian-only registration"
```

---

### Task 3: Email verification and password reset

**Files:**

- Modify: `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`
- Create: `resources/js/pages/auth/verify-email.tsx`, `resources/js/pages/auth/forgot-password.tsx`, `resources/js/pages/auth/reset-password.tsx`
- Test: `tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Auth/PasswordResetTest.php`

**Interfaces:**

- Consumes: the Task 1 and 2 provider and pages.
- Produces: `User implements MustVerifyEmail`; `User::hasVerifiedEmail(): bool` returns true when `email` is null. Pages `auth/verify-email` `{ status: string|null }`, `auth/forgot-password` `{ status: string|null }`, `auth/reset-password` `{ token: string, email: string }`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Auth/EmailVerificationTest --no-interaction`:

```php
<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('emails a verification link after sign-up', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Ngozi Okafor',
        'email' => 'ngozi@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ]);

    Notification::assertSentTo(User::firstWhere('email', 'ngozi@example.com'), VerifyEmail::class);
});

it('shows the verification notice to unverified users', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/verify-email'));
});

it('verifies the email from the signed link', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('treats a student without an email as verified and never emails them', function () {
    Notification::fake();
    $student = User::factory()->student()->unverified()->create(['email' => null]);

    expect($student->hasVerifiedEmail())->toBeTrue();

    $student->sendEmailVerificationNotification();

    Notification::assertNothingSent();
});
```

`php artisan make:test --pest Auth/PasswordResetTest --no-interaction`:

```php
<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the forgot-password page', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));
});

it('emails a reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'parent@example.com']);

    $this->post(route('password.email'), ['email' => 'parent@example.com'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('shows the reset page with the token and email', function () {
    $this->get(route('password.reset', ['token' => 'abc123', 'email' => 'parent@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/reset-password')
            ->where('token', 'abc123')
            ->where('email', 'parent@example.com'));
});

it('resets the password with a valid token', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'parent@example.com']);
    $this->post(route('password.email'), ['email' => 'parent@example.com']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertRedirect(route('login'));

        return Hash::check('a-new-strong-password', $user->fresh()->password);
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/EmailVerificationTest.php tests/Feature/Auth/PasswordResetTest.php`
Expected: FAIL. No verification notification is sent (`User` isn't `MustVerifyEmail`) and no views are registered.

- [ ] **Step 3: `User` implements `MustVerifyEmail`**

In `app/Models/User.php`: replace the commented `// use Illuminate\Contracts\Auth\MustVerifyEmail;` with `use Illuminate\Contracts\Auth\MustVerifyEmail;`, declare `class User extends Authenticatable implements MustVerifyEmail`, and add:

```php
/**
 * Students created without an email have nothing to verify.
 */
public function hasVerifiedEmail(): bool
{
    return $this->email === null || $this->email_verified_at !== null;
}

/**
 * Never try to email an account that has no address.
 */
public function sendEmailVerificationNotification(): void
{
    if ($this->email !== null) {
        parent::sendEmailVerificationNotification();
    }
}
```

`Authenticatable` already uses the `MustVerifyEmail` trait (`Illuminate\Auth\MustVerifyEmail`), so `parent::` resolves to the trait's method.

- [ ] **Step 4: Register the views**

In `FortifyServiceProvider::boot()`:

```php
Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
    'status' => $request->session()->get('status'),
]));

Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
    'status' => $request->session()->get('status'),
]));

Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
    'token' => (string) $request->route('token'),
    'email' => (string) $request->query('email', ''),
]));
```

- [ ] **Step 5: Pages**

`resources/js/pages/auth/verify-email.tsx`:

```tsx
import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status: string | null }) {
    return (
        <AuthLayout
            title="Check your email"
            description="We've sent you a link to confirm your email address. Click it to continue."
        >
            <Head title="Verify email" />
            {status === 'verification-link-sent' && (
                <p className="mb-4 rounded-md bg-present-soft px-3 py-2 text-sm text-present">
                    A new link is on its way to your inbox.
                </p>
            )}
            <Form {...send.form()} className="flex flex-col gap-3">
                {({ processing }) => (
                    <>
                        <Button type="submit" disabled={processing}>
                            Resend the link
                        </Button>
                        <Link
                            href={logout()}
                            as="button"
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            Log out
                        </Link>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

`resources/js/pages/auth/forgot-password.tsx`:

```tsx
import { Form, Head, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status: string | null }) {
    return (
        <AuthLayout
            title="Forgot your password?"
            description="Enter your email and we'll send you a reset link. Students: ask your parent or tutor to reset yours."
        >
            <Head title="Forgot password" />
            {status && (
                <p className="mb-4 rounded-md bg-present-soft px-3 py-2 text-sm text-present">
                    {status}
                </p>
            )}
            <Form {...email.form()} className="flex flex-col gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                autoFocus
                                required
                                aria-invalid={!!errors.email}
                                aria-describedby="email-error"
                            />
                            <FieldError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>
                        <Button type="submit" size="lg" disabled={processing}>
                            Email me a reset link
                        </Button>
                        <Link
                            href={login()}
                            className="text-center text-sm text-primary underline-offset-4 hover:underline"
                        >
                            Back to log in
                        </Link>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

`resources/js/pages/auth/reset-password.tsx`:

```tsx
import { Form, Head } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { update } from '@/routes/password';

type ResetPasswordProps = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    return (
        <AuthLayout title="Choose a new password">
            <Head title="Reset password" />
            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                readOnly
                            />
                            <FieldError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password">New password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
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
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password_confirmation">
                                Confirm new password
                            </Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                            />
                        </div>
                        <Button type="submit" size="lg" disabled={processing}>
                            Reset password
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
```

If `transform` isn't a supported `<Form>` prop in the installed `@inertiajs/react` (check `node_modules/@inertiajs/react/types`), use hidden inputs instead: `<input type="hidden" name="token" value={token} />` and `<input type="hidden" name="email" value={email} />`. Ledger the choice.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/Auth
```

Expected: PASS (all Auth tests: 5 + 5 + 4 + 4 = 18).

- [ ] **Step 7: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
git add -A && git commit -m "feat: email verification and password reset"
```

---

### Task 4: Role dashboards, safe shared props, role middleware

**Files:**

- Create: `app/Http/Controllers/DashboardController.php`, `app/Http/Middleware/EnsureUserHasRole.php`, `resources/js/layouts/app-layout.tsx`, `resources/js/pages/dashboard/{admin,tutor,student,guardian}.tsx`
- Modify: `routes/web.php`, `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/auth.ts`
- Test: `tests/Feature/DashboardTest.php`, `tests/Feature/EnsureUserHasRoleTest.php`

**Interfaces:**

- Consumes: `Role`, `User::hasRole()`, and the Fortify login (Tasks 1–3).
- Produces: route `dashboard` (`GET /dashboard`, middleware `auth`, `verified`) rendering `dashboard/{role->value}`. Middleware alias `role` (`->middleware('role:admin,tutor')`). Shared prop `auth.user: { id, name, email, username, role } | null`. TS `User` and `Role` types. `AppLayout({ title, children })`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest DashboardTest --no-interaction`:

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows each role its own dashboard', function (string $state, string $component) {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'admin' => ['admin', 'dashboard/admin'],
    'tutor' => ['tutor', 'dashboard/tutor'],
    'student' => ['student', 'dashboard/student'],
    'guardian' => ['guardian', 'dashboard/guardian'],
]);

it('makes unverified guardians verify first', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

it('lets a student without an email straight in', function () {
    $student = User::factory()->student()->unverified()->create(['email' => null]);

    $this->actingAs($student)->get(route('dashboard'))->assertOk();
});

it('shares only safe user fields with the page', function () {
    $student = User::factory()->student()->create(['username' => 'ava.okafor']);

    $this->actingAs($student)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $student->id)
            ->where('auth.user.username', 'ava.okafor')
            ->where('auth.user.role', 'student')
            ->has('auth.user', 5)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token'));
});

it('redirects signed-in users away from the login and register pages', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertRedirect('/dashboard');
})->with(['login', 'register']);
```

`php artisan make:test --pest EnsureUserHasRoleTest --no-interaction`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:admin,tutor'])
        ->get('/_test/staff-only', fn () => 'ok');
});

it('allows users with a listed role', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get('/_test/staff-only')
        ->assertOk();
})->with(['admin', 'tutor']);

it('forbids users without a listed role', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get('/_test/staff-only')
        ->assertForbidden();
})->with(['student', 'guardian']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/DashboardTest.php tests/Feature/EnsureUserHasRoleTest.php`
Expected: FAIL with `Route [dashboard] not defined` and `Target class [role] does not exist`.

- [ ] **Step 3: Controller, middleware, route**

`php artisan make:controller DashboardController --invokable --no-interaction`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the signed-in user the dashboard for their role.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard/'.$request->user()->role->value);
    }
}
```

`php artisan make:middleware EnsureUserHasRole --no-interaction`:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only if the user has one of the given roles, e.g. `role:admin,tutor`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        abort_unless($request->user()?->hasRole(...$allowed), 403);

        return $next($request);
    }
}
```

In `bootstrap/app.php`, inside `withMiddleware`, add `use App\Http\Middleware\EnsureUserHasRole;` at the top and:

```php
$middleware->alias([
    'role' => EnsureUserHasRole::class,
]);
```

In `routes/web.php`, add `use App\Http\Controllers\DashboardController;` and:

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});
```

- [ ] **Step 4: Safe shared props**

In `app/Http/Middleware/HandleInertiaRequests.php` `share()`:

```php
'auth' => [
    'user' => $request->user()?->only(['id', 'name', 'email', 'username', 'role']),
],
```

`resources/js/types/auth.ts`:

```ts
export type Role = 'admin' | 'tutor' | 'student' | 'guardian';

export type User = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    role: Role;
};

export type Auth = {
    user: User | null;
};
```

- [ ] **Step 5: App layout and dashboards**

`resources/js/layouts/app-layout.tsx`:

```tsx
import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { Badge } from '@/components/ui/badge';
import { dashboard, logout } from '@/routes';
import type { Role } from '@/types';

const roleLabels: Record<Role, string> = {
    admin: 'Admin',
    tutor: 'Tutor',
    student: 'Student',
    guardian: 'Parent',
};

type AppLayoutProps = {
    title: string;
    children: ReactNode;
};

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-background">
            <Head title={title} />
            <header className="border-b bg-surface">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
                    <Link href={dashboard()} aria-label="Dashboard">
                        <AppLogo className="text-lg" />
                    </Link>
                    {auth.user && (
                        <div className="flex items-center gap-3">
                            <span className="hidden text-sm font-medium sm:inline">
                                {auth.user.name}
                            </span>
                            <Badge tone="brand">
                                {roleLabels[auth.user.role]}
                            </Badge>
                            <Link
                                href={logout()}
                                as="button"
                                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >
                                Log out
                            </Link>
                        </div>
                    )}
                </div>
            </header>
            <main className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8">
                <h1 className="font-display text-3xl font-bold tracking-wide uppercase">
                    {title}
                </h1>
                {children}
            </main>
        </div>
    );
}
```

Ensure `resources/js/types/index.ts` re-exports `Role` (it already does `export type * from './auth'`).

Create the four dashboards. Each one is an empty-state card naming what arrives in later phases:

`resources/js/pages/dashboard/admin.tsx`:

```tsx
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function AdminDashboard() {
    return (
        <AppLayout title="Admin dashboard">
            <Card>
                <CardHeader>
                    <CardTitle>Nothing to manage yet</CardTitle>
                    <CardDescription>
                        Users, classes, lateness reports and the question bank
                        will appear here.
                    </CardDescription>
                </CardHeader>
                <CardContent />
            </Card>
        </AppLayout>
    );
}
```

`resources/js/pages/dashboard/tutor.tsx`: the same structure, with title `"Today"`, card title `"No classes scheduled"` and description `"Your classes, check-in and registers will appear here."`. The component is named `TutorDashboard`.

`resources/js/pages/dashboard/student.tsx`: title `"Your dashboard"`, card title `"Your quizzes are on the way"`, description `"Soon you'll find maths quizzes for your year group here."`. Component `StudentDashboard`.

`resources/js/pages/dashboard/guardian.tsx`: title `"Your family"`, card title `"Add your children"`, description `"You'll be able to add your children, see their attendance and manage subscriptions here."`. Component `GuardianDashboard`.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan wayfinder:generate --with-form
php artisan test --compact tests/Feature/DashboardTest.php tests/Feature/EnsureUserHasRoleTest.php
```

Expected: PASS (10 + 4 = 14 tests). If the guest-redirect test for `login`/`register` fails, check `bootstrap/app.php`: Laravel 13's `redirectUsersTo` default is `/dashboard` via `RedirectIfAuthenticated`. If it isn't, set `$middleware->redirectUsersTo('/dashboard');` and ledger it.

- [ ] **Step 7: Check, format, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check
git add -A && git commit -m "feat: role dashboards, role middleware and safe shared props"
```

---

### Task 5: Branded landing page, log out, build

**Files:**

- Modify: `resources/js/pages/welcome.tsx`, `routes/web.php`
- Test: `tests/Feature/WelcomeTest.php`, `tests/Feature/Auth/LogoutTest.php`

**Interfaces:**

- Consumes: the `login`, `register`, `dashboard` and `logout` routes, and `AppLogo`.
- Produces: page `welcome` with prop `{ canRegister: bool }`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest WelcomeTest --no-interaction`:

```php
<?php

use Inertia\Testing\AssertableInertia as Assert;

it('shows the branded landing page with sign-up enabled', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', true));
});
```

`php artisan make:test --pest Auth/LogoutTest --no-interaction`:

```php
<?php

use App\Models\User;

it('logs the user out and returns them to the landing page', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/WelcomeTest.php tests/Feature/Auth/LogoutTest.php`
Expected: WelcomeTest FAILS (no `canRegister` prop). LogoutTest may already pass, because Fortify provides it. If so, note in the ledger that it is a regression guard on vendor behaviour, not new behaviour.

- [ ] **Step 3: Route prop**

In `routes/web.php`, replace `Route::inertia('/', 'welcome')->name('home');` with:

```php
Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');
```

and add `use Laravel\Fortify\Features;`.

- [ ] **Step 4: Landing page**

Replace `resources/js/pages/welcome.tsx`:

```tsx
import { Head, Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { dashboard, login, register } from '@/routes';

const highlights = [
    {
        title: 'KS2 & KS3 maths',
        body: 'Quizzes matched to every year group, from Year 3 to Year 9.',
    },
    {
        title: '11+, SATs & GCSE',
        body: 'Focused exam practice when it matters most.',
    },
    {
        title: 'Attendance you can trust',
        body: 'On-time records for every class, for students and tutors alike.',
    },
];

export default function Welcome({ canRegister }: { canRegister: boolean }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="min-h-screen bg-background">
                <header className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                    <AppLogo className="text-lg" />
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="text-sm font-medium text-primary"
                            >
                                Go to dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="text-sm font-medium text-foreground"
                                >
                                    Log in
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="inline-flex h-10 items-center rounded-md bg-accent px-4 text-sm font-medium text-accent-foreground shadow-card hover:bg-accent-hover"
                                    >
                                        Sign up
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>
                <main className="mx-auto flex max-w-6xl flex-col gap-12 px-4 py-16">
                    <section className="flex flex-col gap-4">
                        <h1 className="font-display text-4xl leading-tight font-bold tracking-wide text-primary uppercase sm:text-6xl">
                            Confident in maths. On time, every time.
                        </h1>
                        <p className="max-w-2xl text-lg text-muted-foreground">
                            Jerry Great Academy brings year-group maths quizzes,
                            exam preparation and fair attendance tracking
                            together in one place.
                        </p>
                    </section>
                    <section className="grid gap-4 md:grid-cols-3">
                        {highlights.map((item) => (
                            <div
                                key={item.title}
                                className="rounded-lg border bg-surface p-5 shadow-card"
                            >
                                <h2 className="font-semibold">{item.title}</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {item.body}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>
            </div>
        </>
    );
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/WelcomeTest.php tests/Feature/Auth/LogoutTest.php tests/Feature/ExampleTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Full verification and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run check:fix && npm run types:check && npm run build
php artisan test --compact
php artisan migrate:fresh --seed --no-interaction
git add -A && git commit -m "feat: branded landing page"
```

Expected: the build succeeds and the whole suite passes. Then, in a browser at `/login`, the seeded `parent@academy.test` / `password` reaches "Your family", and `ks2.student@academy.test` reaches "Your dashboard". Seeded students have emails; a username login is covered by tests.
