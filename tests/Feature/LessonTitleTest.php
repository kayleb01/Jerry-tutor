<?php

use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function lessonFor(string $className, string $subjectName): Lesson
{
    $group = ClassGroup::factory()->create(['name' => $className]);
    $slot = ClassSlot::factory()->for($group, 'classGroup')->create([
        'subject_id' => Subject::query()->firstOrCreate(['name' => $subjectName])->id,
    ]);

    return Lesson::factory()->create(['class_group_id' => $group->id, 'class_slot_id' => $slot->id]);
}

it('names a lesson by class and subject', function () {
    expect(lessonFor('Year 5', 'Science')->title())->toBe('Year 5 · Science');
});

it('does not repeat a subject already in the class name', function () {
    expect(lessonFor('Year 5 Maths', 'Maths')->title())->toBe('Year 5 Maths')
        ->and(lessonFor('Year 6 maths club', 'Maths')->title())->toBe('Year 6 maths club')
        ->and(lessonFor('Year 5 Maths', 'Science')->title())->toBe('Year 5 Maths · Science');
});

it('only treats the subject as repeated when it is a whole word', function () {
    expect(lessonFor('Year 5 Mathsy', 'Maths')->title())->toBe('Year 5 Mathsy · Maths');
});

it('shows the lesson title on the register', function () {
    $lesson = lessonFor('Year 5', 'Science');
    $tutor = User::query()->find($lesson->tutor_id);

    $this->actingAs($tutor)->get(route('lessons.register.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page->where('lesson.className', 'Year 5 · Science'));
});
