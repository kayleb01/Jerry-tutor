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

it('keeps a student able to log in after an admin adds an email', function () {
    $student = User::factory()->student()->unverified()->create(['email' => null, 'username' => 'ava.okafor']);

    $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
        'name' => $student->name,
        'username' => 'ava.okafor',
        'email' => 'ava@home.test',
        'year_group' => 5,
    ]);

    $this->actingAs($student->fresh())->get(route('dashboard'))->assertOk();
});

it('marks tutor applicants that are not approved yet', function () {
    User::factory()->tutor()->create(['name' => 'Bola Approved']);
    User::factory()->pendingTutor()->create(['name' => 'Chidi Pending']);
    User::factory()->rejectedTutor()->create(['name' => 'Dayo Rejected']);

    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'tutor']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 3)
            ->where('users.data.0.applicationStatus', null)
            ->where('users.data.1.applicationStatus', 'pending')
            ->where('users.data.2.applicationStatus', 'rejected'));
});
