<?php

use App\Models\Subject;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('starts with Maths', function () {
    expect(Subject::query()->pluck('name')->all())->toBe(['Maths']);
    expect(Subject::options())->toBe([['value' => Subject::query()->first()->id, 'label' => 'Maths']]);
});

it('lets an admin add, rename and deactivate a subject', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.subjects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/subjects/index')->has('subjects', 1));

    $this->actingAs($admin)->post(route('admin.subjects.store'), ['name' => ' English '])->assertRedirect();
    $subject = Subject::query()->where('name', 'English')->firstOrFail();
    expect($subject->is_active)->toBeTrue();

    $this->actingAs($admin)->put(route('admin.subjects.update', $subject), ['name' => 'English Language'])->assertRedirect();
    expect($subject->fresh()->name)->toBe('English Language');

    $this->actingAs($admin)->put(route('admin.subjects.update', $subject), ['name' => 'English Language', 'is_active' => false])->assertRedirect();
    expect($subject->fresh()->is_active)->toBeFalse();
    expect(collect(Subject::options())->pluck('label')->all())->toBe(['Maths']);

    $this->actingAs($admin)->put(route('admin.subjects.update', $subject), ['is_active' => true])->assertRedirect();
    expect($subject->fresh())->name->toBe('English Language')->is_active->toBeTrue();
});

it('refuses a duplicate subject name, ignoring case', function () {
    $admin = User::factory()->admin()->create();
    $other = Subject::factory()->create(['name' => 'Science']);

    $this->actingAs($admin)->post(route('admin.subjects.store'), ['name' => 'maths'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->put(route('admin.subjects.update', $other), ['name' => 'MATHS'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->put(route('admin.subjects.update', $other), ['name' => 'SCIENCE'])->assertSessionHasNoErrors();

    expect(Subject::query()->count())->toBe(2);
});

it('is for admins only', function (string $state) {
    $user = User::factory()->{$state}()->create();
    $subject = Subject::query()->first();

    $this->actingAs($user)->get(route('admin.subjects.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.subjects.store'), ['name' => 'Art'])->assertForbidden();
    $this->actingAs($user)->put(route('admin.subjects.update', $subject), ['name' => 'Art'])->assertForbidden();
})->with(['tutor', 'student', 'guardian']);
