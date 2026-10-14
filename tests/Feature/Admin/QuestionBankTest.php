<?php

use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\Strand;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->strand = Strand::factory()->create(['name' => 'Number', 'slug' => 'number']);
    $this->topic = Topic::factory()->for($this->strand)->create(['key_stage' => KeyStage::Ks2, 'name' => 'Fractions']);
});

function questionPayload(array $overrides = []): array
{
    return [
        'topic_id' => test()->topic->id,
        'difficulty' => 'intermediate',
        'year_group' => 5,
        'type' => 'multiple_choice',
        'stem' => 'What is $\frac{1}{4}$ of 12?',
        'options' => ['3', '4', '6'],
        'correct' => [0],
        'explanation' => 'Divide 12 into 4 equal groups.',
        'marks' => 1,
        'exam_tracks' => ['sats'],
        ...$overrides,
    ];
}

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('admin.questions.index'))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists questions with status counts and filters', function () {
    Question::factory()->for($this->topic)->published()->create(['stem' => 'Halve 18']);
    Question::factory()->for($this->topic)->create(['stem' => 'Double 7']);
    Question::factory()->create(['stem' => 'Elsewhere']);

    $this->actingAs($this->admin)->get(route('admin.questions.index', ['status' => 'published', 'search' => 'HALVE']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/questions/index')
            ->has('questions.data', 1)
            ->where('questions.data.0.stem', 'Halve 18')
            ->where('questions.data.0.topic', 'Fractions')
            ->where('questions.data.0.keyStage', 'KS2')
            ->where('questions.data.0.canDelete', false)
            ->where('questions.data.0.nextStatuses.0.value', 'retired')
            ->where('counts.published', 1)
            ->where('counts.draft', 2)
            ->where('filters.status', 'published')
            ->has('options.topics', 2)
            ->where('options.strands.0.value', 'number'));

    $this->actingAs($this->admin)->get(route('admin.questions.index', ['topic' => $this->topic->id]))
        ->assertInertia(fn (Assert $page) => $page->has('questions.data', 2));
});

it('creates a draft question from the form', function () {
    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload())
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $question = Question::sole();

    expect($question->status)->toBe(QuestionStatus::Draft)
        ->and($question->author_id)->toBe($this->admin->id)
        ->and($question->options->pluck('body')->all())->toBe(['3', '4', '6']);
});

it('shows form errors with the shared rules', function () {
    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload(['correct' => [0, 1], 'year_group' => 9]))
        ->assertSessionHasErrors(['year_group']);

    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload(['correct' => [0, 1]]))
        ->assertSessionHasErrors(['correct' => 'A multiple-choice question has exactly one correct answer.']);

    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload(['type' => 'multi_select', 'correct' => [0, 1, 2]]))
        ->assertSessionHasErrors(['correct' => 'At least one option must be wrong.']);

    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload(['type' => 'numeric', 'numeric_answer' => '']))
        ->assertSessionHasErrors(['numeric_answer' => 'Enter the correct number.']);

    expect(Question::count())->toBe(0);
});

it('refuses SVG diagrams', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload([
        'image' => UploadedFile::fake()->createWithContent('shape.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
    ]))->assertSessionHasErrors(['image']);
});

it('edits a question and keeps its status', function () {
    Storage::fake('public');
    $question = Question::factory()->for($this->topic)->published()->create();

    $this->actingAs($this->admin)->get(route('admin.questions.edit', $question))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/questions/edit')
            ->where('question.id', $question->id)
            ->where('question.options', ['12', '14', '16', '18'])
            ->where('question.correct', [0])
            ->where('question.status', 'published')
            ->where('question.canDelete', false));

    $this->actingAs($this->admin)
        ->post(route('admin.questions.update', $question), [
            ...questionPayload(['type' => 'numeric', 'numeric_answer' => '3']),
            '_method' => 'put',
            'image' => UploadedFile::fake()->image('diagram.png'),
        ])
        ->assertRedirect(route('admin.questions.edit', $question));

    expect($question->fresh()->status)->toBe(QuestionStatus::Published)
        ->and($question->fresh()->options)->toHaveCount(0)
        ->and($question->fresh()->image_path)->not->toBeNull();
});

it('deletes drafts but not published questions', function () {
    $draft = Question::factory()->create();
    $published = Question::factory()->published()->create();

    $this->actingAs($this->admin)->delete(route('admin.questions.destroy', $draft))
        ->assertRedirect(route('admin.questions.index'));
    $this->actingAs($this->admin)->delete(route('admin.questions.destroy', $published))
        ->assertInertiaFlash('toast.type', 'error');

    expect($draft->fresh()->trashed())->toBeTrue()
        ->and($published->fresh()->trashed())->toBeFalse();
});
