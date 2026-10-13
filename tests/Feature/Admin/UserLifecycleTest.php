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
