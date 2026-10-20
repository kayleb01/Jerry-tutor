<?php

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function studentPayload(array $overrides = []): array
{
    return [
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
        'year_group' => YearGroup::Year5->value,
        ...$overrides,
    ];
}

it('lists the tutor\'s classes with their slots and active students', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->withSlot($tutor, ['weekday' => Weekday::Monday, 'start_time' => '09:00', 'duration_minutes' => 120])->create(['name' => 'Year 5 A']);
    $current = User::factory()->student()->create();
    $left = User::factory()->student()->create();
    $group->enrol($current);
    $group->enrol($left);
    $group->withdraw($left);
    ClassGroup::factory()->withSlot()->create(['name' => 'Someone else']);

    $this->actingAs($tutor)->get(route('tutor.classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tutor/classes/index')
            ->has('classes', 1)
            ->where('classes.0.name', 'Year 5 A')
            ->where('classes.0.slots.0.label', 'Mon 09:00–11:00 · Maths')
            ->has('classes.0.students', 1)
            ->where('classes.0.students.0.name', $current->name)
            ->where('classes.0.canAddStudent', true));
});

it('offers adding students only on the tutor\'s active classes', function () {
    $tutor = User::factory()->tutor()->create();
    ClassGroup::factory()->withSlot($tutor)->create(['name' => 'A Active']);
    ClassGroup::factory()->withSlot($tutor)->create(['name' => 'B Inactive', 'is_active' => false]);

    $this->actingAs($tutor)->get(route('tutor.classes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classes.0.canAddStudent', true)
            ->where('classes.1.canAddStudent', false));
});

it('adds a student to a class the tutor teaches', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->withSlot($tutor)->create(['name' => 'Year 5 A', 'year_group' => YearGroup::Year6]);

    $this->actingAs($tutor)->get(route('tutor.classes.students.create', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tutor/students/create')
            ->where('classGroup.name', 'Year 5 A')
            ->where('classGroup.yearGroup', YearGroup::Year6->value));

    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload(['email' => 'Ava@Example.com', 'year_group' => YearGroup::Year6->value]))
        ->assertRedirect(route('tutor.classes.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Ava Okafor added to Year 5 A.');

    $student = User::query()->where('username', 'ava.okafor')->sole();
    expect($student->role)->toBe(Role::Student)
        ->and($student->email)->toBe('ava@example.com')
        ->and($student->email_verified_at)->not->toBeNull()
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year6)
        ->and($group->activeStudents()->pluck('users.id')->all())->toBe([$student->id]);
});

it('creates a student without an email', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->withSlot($tutor)->create();

    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload())->assertRedirect();

    expect(User::query()->where('username', 'ava.okafor')->sole()->email)->toBeNull();
});

it('enforces username and email rules', function () {
    $tutor = User::factory()->tutor()->create();
    $group = ClassGroup::factory()->withSlot($tutor)->create();
    User::factory()->student()->create(['username' => 'taken.name', 'email' => 'taken@example.com']);

    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload(['username' => 'Bad Name!']))
        ->assertSessionHasErrors('username');
    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload(['username' => 'taken.name']))
        ->assertSessionHasErrors('username');
    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload(['email' => 'TAKEN@example.com']))
        ->assertSessionHasErrors('email');
    $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload(['year_group' => 99]))
        ->assertSessionHasErrors('year_group');

    expect(User::query()->where('username', 'ava.okafor')->exists())->toBeFalse();
});

it('refuses classes the tutor does not teach', function () {
    $tutor = User::factory()->tutor()->create();
    $other = ClassGroup::factory()->withSlot()->create();
    $removed = ClassGroup::factory()->withSlot($tutor)->create();
    $removed->slots()->sole()->delete();
    $inactive = ClassGroup::factory()->withSlot($tutor)->create(['is_active' => false]);

    foreach ([$other, $removed, $inactive] as $group) {
        $this->actingAs($tutor)->get(route('tutor.classes.students.create', $group))->assertForbidden();
        $this->actingAs($tutor)->post(route('tutor.classes.students.store', $group), studentPayload())->assertForbidden();
    }

    expect(User::query()->where('username', 'ava.okafor')->exists())->toBeFalse();
});

it('is not available to admins, students or guardians through these routes', function (string $state) {
    $group = ClassGroup::factory()->withSlot()->create();
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('tutor.classes.index'))->assertForbidden();
    $this->actingAs($user)->get(route('tutor.classes.students.create', $group))->assertForbidden();
    $this->actingAs($user)->post(route('tutor.classes.students.store', $group), studentPayload())->assertForbidden();
})->with(['admin', 'student', 'guardian']);

it('refuses another tutor\'s class before checking the form', function () {
    $tutor = User::factory()->tutor()->create();
    $other = ClassGroup::factory()->withSlot()->create();

    $this->actingAs($tutor)
        ->post(route('tutor.classes.students.store', $other), studentPayload(['username' => 'Not Valid!', 'name' => '']))
        ->assertForbidden();
});
