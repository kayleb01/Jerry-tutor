<?php

use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->group = ClassGroup::factory()->create();
});

it('enrols a student', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertRedirect(route('admin.classes.edit', $this->group))
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->group->activeStudents()->pluck('users.id')->all())->toBe([$student->id]);
});

it('only enrols active students', function () {
    $deactivated = User::factory()->student()->create();
    $deactivated->delete();

    foreach ([User::factory()->guardian()->create()->id, $deactivated->id, 999999] as $id) {
        $this->actingAs($this->admin)
            ->post(route('admin.classes.students.store', $this->group), ['student_id' => $id])
            ->assertSessionHasErrors('student_id');
    }

    expect($this->group->students()->count())->toBe(0);
});

it('rejects a student who is already enrolled', function () {
    $student = User::factory()->student()->create();
    $this->group->enrol($student);

    $this->actingAs($this->admin)
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertSessionHasErrors('student_id');
});

it('withdraws a student but keeps the history, and can re-enrol them', function () {
    $student = User::factory()->student()->create();
    $this->group->enrol($student);

    $this->actingAs($this->admin)
        ->delete(route('admin.classes.students.destroy', [$this->group, $student]))
        ->assertRedirect(route('admin.classes.edit', $this->group));

    expect($this->group->activeStudents()->count())->toBe(0)
        ->and($this->group->students()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertSessionHasNoErrors();

    expect($this->group->activeStudents()->count())->toBe(1);
});

it('404s when withdrawing someone who is not in the class', function () {
    $this->actingAs($this->admin)
        ->delete(route('admin.classes.students.destroy', [$this->group, User::factory()->student()->create()]))
        ->assertNotFound();
});

it('shows enrolled students and the students who can still be added', function () {
    $enrolled = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava']);
    $this->group->enrol($enrolled);
    User::factory()->student(YearGroup::Year6)->create(['name' => 'Bola']);
    User::factory()->guardian()->create(['name' => 'Not A Student']);

    $this->actingAs($this->admin)->get(route('admin.classes.edit', $this->group))
        ->assertInertia(fn (Assert $page) => $page
            ->has('students', 1)
            ->where('students.0.name', 'Ava')
            ->where('students.0.yearGroup', 'Year 5')
            ->has('candidates', 1)
            ->where('candidates.0.label', 'Bola (Year 6)'));
});

it('keeps enrolment admin-only', function () {
    $student = User::factory()->student()->create();

    $this->actingAs(User::factory()->tutor()->create())
        ->post(route('admin.classes.students.store', $this->group), ['student_id' => $student->id])
        ->assertForbidden();
});
