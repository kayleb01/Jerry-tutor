<?php

use App\Enums\ApprovalStatus;
use App\Enums\Weekday;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Services\LessonScheduler;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-01 11:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->tutor = User::factory()->tutor()->create(['name' => 'Tunde Tutor']);
    $this->maths = Subject::query()->firstOrCreate(['name' => 'Maths']);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5']);
});

function slotPayload(array $overrides = []): array
{
    return [
        'subject_id' => test()->maths->id,
        'tutor_id' => test()->tutor->id,
        'weekday' => Weekday::Saturday->value,
        'start_time' => '10:00',
        'duration_minutes' => 60,
        'location' => 'Room 2',
        ...$overrides,
    ];
}

function mondaySlot(string $start = '09:00', int $minutes = 120): ClassSlot
{
    return ClassSlot::factory()->create([
        'class_group_id' => test()->group->id,
        'subject_id' => test()->maths->id,
        'tutor_id' => test()->tutor->id,
        'weekday' => Weekday::Monday,
        'start_time' => $start,
        'duration_minutes' => $minutes,
    ]);
}

it('adds a slot and generates its lessons', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload())
        ->assertRedirect(route('admin.classes.edit', $this->group))
        ->assertInertiaFlash('toast.type', 'success');

    $slot = $this->group->slots()->first();
    expect($slot->location)->toBe('Room 2')
        ->and($slot->lessons()->count())->toBe(4);
});

it('adds an unscheduled slot without lessons', function () {
    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['weekday' => null, 'start_time' => null, 'tutor_id' => null, 'location' => null]))
        ->assertSessionHasNoErrors();

    expect($this->group->slots()->first()->lessons()->count())->toBe(0);
});

it('edits a slot and reschedules only that slot', function () {
    $slot = mondaySlot();
    $other = ClassSlot::factory()->create(['class_group_id' => $this->group->id, 'tutor_id' => $this->tutor->id, 'weekday' => Weekday::Friday, 'start_time' => '15:00']);
    app(LessonScheduler::class)->generate();
    $otherIds = $other->lessons()->pluck('id')->all();

    $this->actingAs($this->admin)->put(route('admin.classes.slots.update', [$this->group, $slot]), slotPayload(['weekday' => Weekday::Tuesday->value, 'start_time' => '14:00']))
        ->assertRedirect(route('admin.classes.edit', $this->group));

    expect($slot->fresh()->timeLabel())->toBe('Tue 14:00–15:00')
        ->and($slot->lessons()->count())->toBe(4)
        ->and($slot->lessons()->get()->every(fn (Lesson $lesson) => $lesson->localStart()->dayOfWeekIso === 2))->toBeTrue()
        ->and($other->lessons()->pluck('id')->all())->toBe($otherIds);
});

it('refuses a slot that overlaps another slot of the class on the same day', function () {
    mondaySlot('09:00', 120);

    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['weekday' => 1, 'start_time' => '10:30']))
        ->assertSessionHasErrors(['start_time' => 'Year 5 already has Maths 09:00–11:00 on Monday']);

    expect($this->group->slots()->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['weekday' => 1, 'start_time' => '11:00']))
        ->assertSessionHasNoErrors();
    expect($this->group->slots()->count())->toBe(2);
});

it('does not count the slot being edited, a removed slot or another day as overlapping', function () {
    $slot = mondaySlot('09:00', 120);
    $removed = mondaySlot('13:00', 60);
    $removed->delete();

    $this->actingAs($this->admin)->put(route('admin.classes.slots.update', [$this->group, $slot]), slotPayload(['weekday' => 1, 'start_time' => '09:30']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['weekday' => 1, 'start_time' => '13:00']))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['weekday' => 2, 'start_time' => '09:30']))
        ->assertSessionHasNoErrors();
});

it('finds the overlapping slot, treating adjacent and unscheduled slots as free', function () {
    $slot = mondaySlot('09:00', 120);
    ClassSlot::factory()->create(['class_group_id' => $this->group->id, 'weekday' => null, 'start_time' => null]);

    expect(ClassSlot::overlapping($this->group, Weekday::Monday, '08:00', 61)?->id)->toBe($slot->id)
        ->and(ClassSlot::overlapping($this->group, Weekday::Monday, '08:00', 60))->toBeNull()
        ->and(ClassSlot::overlapping($this->group, Weekday::Monday, '09:30', 15, $slot->id))->toBeNull()
        ->and(ClassSlot::overlapping($this->group, Weekday::Tuesday, '09:30', 15))->toBeNull();
});

it('removes a slot, deleting future unattended lessons only', function () {
    $slot = mondaySlot();
    app(LessonScheduler::class)->generate();
    $attended = $slot->lessons()->orderBy('starts_at')->first();
    AttendanceRecord::factory()->create(['lesson_id' => $attended->id]);

    $this->actingAs($this->admin)->delete(route('admin.classes.slots.destroy', [$this->group, $slot]))
        ->assertRedirect(route('admin.classes.edit', $this->group))
        ->assertInertiaFlash('toast.type', 'success');

    expect($slot->fresh()->trashed())->toBeTrue()
        ->and($slot->lessons()->pluck('id')->all())->toBe([$attended->id]);
});

it('does not fail when a new slot starts where a removed slot left an attended lesson', function () {
    $removed = mondaySlot('09:00', 60);
    app(LessonScheduler::class)->generate();
    $attended = $removed->lessons()->orderBy('starts_at')->first();
    AttendanceRecord::factory()->create(['lesson_id' => $attended->id]);

    $this->actingAs($this->admin)->delete(route('admin.classes.slots.destroy', [$this->group, $removed]));
    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['weekday' => 1, 'start_time' => '09:00']))
        ->assertRedirect(route('admin.classes.edit', $this->group));

    $new = $this->group->slots()->first();
    expect($new->lessons()->count())->toBe(3)
        ->and($this->group->lessons()->where('starts_at', $attended->starts_at)->count())->toBe(1);
});

it('lists each class with its subjects and tutors', function () {
    mondaySlot();

    $this->actingAs($this->admin)->get(route('admin.classes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classes.0.slots', ['Maths · Mon 09:00–11:00 · Tunde Tutor']));
});

it('gives the edit page the slots and the options to build them', function () {
    $slot = mondaySlot();

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $this->group))
        ->assertInertia(fn (Assert $page) => $page
            ->where('slots.0.id', $slot->id)
            ->where('slots.0.label', 'Mon 09:00–11:00')
            ->where('slots.0.subject', 'Maths')
            ->where('slots.0.tutor', 'Tunde Tutor')
            ->where('slots.0.weekday', 1)
            ->where('slots.0.startTime', '09:00')
            ->where('slots.0.duration', 120)
            ->has('subjects')
            ->has('tutors', 1)
            ->has('weekdays', 7));
});

it('validates the slot', function (array $overrides, string $field) {
    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'no subject' => [['subject_id' => null], 'subject_id'],
    'unknown subject' => [['subject_id' => 9999], 'subject_id'],
    'day without time' => [['start_time' => null], 'start_time'],
    'time without day' => [['weekday' => null], 'weekday'],
    'bad day' => [['weekday' => 9], 'weekday'],
    'bad time' => [['start_time' => '25:00'], 'start_time'],
    'too short' => [['duration_minutes' => 10], 'duration_minutes'],
    'too long' => [['duration_minutes' => 300], 'duration_minutes'],
    'long location' => [['location' => str_repeat('a', 256)], 'location'],
]);

it('only accepts an active tutor', function (string $state) {
    $tutor = match ($state) {
        'guardian' => User::factory()->guardian()->create(),
        'deleted tutor' => tap(User::factory()->tutor()->create())->delete(),
    };

    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['tutor_id' => $tutor->id]))
        ->assertSessionHasErrors('tutor_id');
})->with(['guardian', 'deleted tutor']);

it('refuses a tutor who is not approved on a new slot', function (string $state) {
    $tutor = User::factory()->{$state}()->create();

    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['tutor_id' => $tutor->id]))
        ->assertSessionHasErrors('tutor_id');
})->with(['pendingTutor', 'rejectedTutor']);

it('keeps a slot\'s existing tutor on update even if they are no longer approved', function () {
    $slot = mondaySlot();
    $this->tutor->forceFill(['approval_status' => ApprovalStatus::Rejected])->save();

    $this->actingAs($this->admin)->put(route('admin.classes.slots.update', [$this->group, $slot]), slotPayload(['duration_minutes' => 90, 'weekday' => Weekday::Monday->value, 'start_time' => '09:00']))
        ->assertSessionHasNoErrors();

    expect($slot->fresh()->tutor_id)->toBe($this->tutor->id);
});

it('lists only approved tutors for the slot form', function () {
    User::factory()->pendingTutor()->create();

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $this->group))
        ->assertInertia(fn (Assert $page) => $page->has('tutors', 1)->where('tutors.0.value', $this->tutor->id));
});

it('refuses an inactive subject on a new slot but keeps one already on the slot', function () {
    $archived = Subject::factory()->create(['name' => 'Latin', 'is_active' => false]);

    $this->actingAs($this->admin)->post(route('admin.classes.slots.store', $this->group), slotPayload(['subject_id' => $archived->id]))
        ->assertSessionHasErrors('subject_id');

    $slot = mondaySlot();
    $slot->update(['subject_id' => $archived->id]);
    $this->actingAs($this->admin)->put(route('admin.classes.slots.update', [$this->group, $slot]), slotPayload(['subject_id' => $archived->id, 'weekday' => 1, 'start_time' => '09:00']))
        ->assertSessionHasNoErrors();
});

it('is admin-only and scoped to the class', function () {
    $slot = mondaySlot();
    $otherGroup = ClassGroup::factory()->create();

    foreach (['tutor', 'guardian', 'student'] as $state) {
        $this->actingAs(User::factory()->{$state}()->create())
            ->post(route('admin.classes.slots.store', $this->group), slotPayload())->assertForbidden();
    }

    $this->actingAs($this->admin)->put(route('admin.classes.slots.update', [$otherGroup, $slot]), slotPayload())->assertNotFound();
    $this->actingAs($this->admin)->delete(route('admin.classes.slots.destroy', [$otherGroup, $slot]))->assertNotFound();
});
