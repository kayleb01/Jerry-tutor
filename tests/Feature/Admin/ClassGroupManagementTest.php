<?php

use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\User;
use App\Services\LessonScheduler;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-01 11:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->tutor = User::factory()->tutor()->create(['name' => 'Tunde Tutor']);
});

function classPayload(array $overrides = []): array
{
    return [
        'name' => 'Year 5',
        'year_group' => 5,
        'is_active' => true,
        ...$overrides,
    ];
}

/**
 * A class with a Saturday 10:00 Maths slot taught by the test tutor, and its upcoming lessons.
 */
function scheduledClass(): ClassGroup
{
    $group = ClassGroup::factory()->withSlot(test()->tutor, ['weekday' => Weekday::Saturday, 'start_time' => '10:00'])->create(['name' => 'Year 5']);
    app(LessonScheduler::class)->generate();

    return $group;
}

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('admin.classes.index'))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists classes with their timetable, size and next lesson', function () {
    $group = scheduledClass();
    $group->enrol(User::factory()->student()->create());
    ClassGroup::factory()->create(['name' => 'Year 6']);

    $this->actingAs($this->admin)->get(route('admin.classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/classes/index')
            ->has('classes', 2)
            ->where('classes.0.name', 'Year 5')
            ->where('classes.0.slots', ['Maths · Sat 10:00–11:00 · Tunde Tutor'])
            ->where('classes.0.students', 1)
            ->where('classes.0.nextLesson', 'Sat 4 Oct, 10:00')
            ->where('classes.1.slots', []));
});

it('creates a class', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload())
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $group = ClassGroup::firstWhere('name', 'Year 5');
    expect($group->year_group->value)->toBe(5)
        ->and($group->slots()->count())->toBe(0);
});

it('validates the name and year group', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad year group' => [['year_group' => 99], 'year_group'],
]);

it('shows the edit page with the class timetable', function () {
    $group = scheduledClass();

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/classes/edit')
            ->where('classGroup.id', $group->id)
            ->where('classGroup.slots', ['Maths · Sat 10:00–11:00 · Tunde Tutor'])
            ->has('yearGroups'));
});

it('clears future lessons when a class is deactivated and brings them back when reactivated', function () {
    $group = scheduledClass();

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload(['is_active' => false]))
        ->assertRedirect(route('admin.classes.edit', $group));
    expect($group->lessons()->count())->toBe(0);

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload());
    expect($group->lessons()->count())->toBe(4);
});

it('leaves lessons alone when only the name changes', function () {
    $group = scheduledClass();
    $ids = $group->lessons()->pluck('id')->all();

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload(['name' => 'Renamed']));

    expect($group->lessons()->pluck('id')->all())->toBe($ids)
        ->and($group->fresh()->name)->toBe('Renamed');
});
