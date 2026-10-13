<?php

use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\User;
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
        'name' => 'Year 5 Maths – Saturday',
        'tutor_id' => test()->tutor->id,
        'year_group' => 5,
        'location' => 'Room 1',
        'weekday' => 6,
        'start_time' => '10:00',
        'duration_minutes' => 60,
        'is_active' => true,
        ...$overrides,
    ];
}

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('admin.classes.index'))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists classes with tutor, schedule, size and next lesson', function () {
    $group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 5 Maths', 'weekday' => Weekday::Saturday, 'start_time' => '10:00']);
    $group->enrol(User::factory()->student()->create());

    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['name' => 'Year 6 Maths']));

    $this->actingAs($this->admin)->get(route('admin.classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/classes/index')
            ->has('classes', 2)
            ->where('classes.0.name', 'Year 5 Maths')
            ->where('classes.0.tutor', 'Tunde Tutor')
            ->where('classes.0.schedule', 'Saturdays at 10:00 · 60 min')
            ->where('classes.0.students', 1)
            ->where('classes.1.nextLesson', 'Sat 4 Oct, 10:00'));
});

it('creates a class and schedules its lessons', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload())
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');
    expect($group->weekday)->toBe(Weekday::Saturday)
        ->and($group->lessons()->count())->toBe(4);
});

it('rejects a tutor who is not a tutor or has been deactivated', function () {
    $deactivated = User::factory()->tutor()->create();
    $deactivated->delete();

    foreach ([User::factory()->student()->create()->id, $deactivated->id] as $tutorId) {
        $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['tutor_id' => $tutorId]))
            ->assertSessionHasErrors('tutor_id');
    }
});

it('needs a weekday and a start time together', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['start_time' => '']))
        ->assertSessionHasErrors('start_time');

    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload(['weekday' => '']))
        ->assertSessionHasErrors('weekday');
});

it('validates times, weekdays and durations', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'bad time' => [['start_time' => '25:00'], 'start_time'],
    'bad weekday' => [['weekday' => 8], 'weekday'],
    'too short' => [['duration_minutes' => 5], 'duration_minutes'],
    'too long' => [['duration_minutes' => 600], 'duration_minutes'],
]);

it('shows the edit page with options', function () {
    $group = ClassGroup::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/classes/edit')
            ->where('classGroup.id', $group->id)
            ->has('weekdays', 7)
            ->has('tutors'));
});

it('reschedules future lessons when the schedule changes', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload());
    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload(['weekday' => 7, 'start_time' => '14:00']))
        ->assertRedirect(route('admin.classes.edit', $group));

    expect($group->lessons()->orderBy('starts_at')->first()->starts_at->toDateTimeString())->toBe('2025-10-05 13:00:00')
        ->and($group->lessons()->count())->toBe(4);
});

it('leaves lessons alone when only the name changes', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.store'), classPayload());
    $group = ClassGroup::firstWhere('name', 'Year 5 Maths – Saturday');
    $ids = $group->lessons()->pluck('id')->all();

    $this->actingAs($this->admin)->put(route('admin.classes.update', $group), classPayload(['name' => 'Renamed']));

    expect($group->lessons()->pluck('id')->all())->toBe($ids);
});
