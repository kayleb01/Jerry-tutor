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
