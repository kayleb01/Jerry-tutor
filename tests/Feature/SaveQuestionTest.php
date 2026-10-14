<?php

use App\Actions\SaveQuestion;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Topic;
use App\Models\User;
use App\Support\QuestionInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->topic = Topic::factory()->create(['key_stage' => KeyStage::Ks2]);
});

function questionInput(array $overrides = []): array
{
    return [
        'topic_id' => test()->topic->id,
        'difficulty' => 'easy',
        'year_group' => '5',
        'type' => 'multiple_choice',
        'stem' => '  What is $\frac{1}{2}$ of 8?  ',
        'options' => ['4', '2', '8', ''],
        'correct' => ['0'],
        'numeric_answer' => '99',
        'accepted_answers' => "four\n",
        'explanation' => '',
        'marks' => '',
        'exam_tracks' => ['sats'],
        ...$overrides,
    ];
}

function validateQuestion(array $input): Illuminate\Validation\Validator
{
    $data = QuestionInput::normalise($input);

    return tap(Validator::make($data, QuestionInput::rules($data), QuestionInput::messages(), QuestionInput::attributes())
        ->after(QuestionInput::after($data)), fn ($validator) => $validator->passes());
}

it('normalises input and keeps only the fields the type uses', function () {
    $data = QuestionInput::normalise(questionInput());

    expect($data)->toMatchArray([
        'stem' => 'What is $\frac{1}{2}$ of 8?',
        'year_group' => '5',
        'explanation' => null,
        'marks' => 1,
        'options' => ['4', '2', '8'],
        'correct' => [0],
        'exam_tracks' => ['sats'],
    ])->not->toHaveKeys(['numeric_answer', 'accepted_answers']);

    expect(QuestionInput::normalise(questionInput(['type' => 'short_text'])))
        ->toHaveKey('accepted_answers', ['four'])
        ->not->toHaveKeys(['options', 'correct', 'numeric_answer']);
});

it('accepts a valid question of each type', function (array $overrides) {
    expect(validateQuestion(questionInput($overrides))->errors()->all())->toBe([]);
})->with([
    'multiple choice' => [[]],
    'multi-select' => [['type' => 'multi_select', 'correct' => ['0', '2']]],
    'numeric' => [['type' => 'numeric', 'numeric_answer' => '0.75', 'numeric_tolerance' => '0.01']],
    'short text' => [['type' => 'short_text', 'accepted_answers' => "four\nFour\n4"]],
]);

it('refuses type-specific mistakes', function (array $overrides, string $field, string $message) {
    expect(validateQuestion(questionInput($overrides))->errors()->first($field))->toBe($message);
})->with([
    'two correct on multiple choice' => [['correct' => ['0', '1']], 'correct', 'A multiple-choice question has exactly one correct answer.'],
    'no correct answer' => [['correct' => []], 'correct', 'Mark the correct answer.'],
    'correct answer out of range' => [['correct' => ['5']], 'correct.0', 'The correct answer must be one of the options.'],
    'one option' => [['options' => ['4']], 'options', 'Add at least two options.'],
    'blank option in the middle' => [['options' => ['4', '', '8']], 'options.1', 'Fill in this option or remove it.'],
    'every option correct on multi-select' => [['type' => 'multi_select', 'options' => ['4', '8'], 'correct' => ['0', '1']], 'correct', 'At least one option must be wrong.'],
    'numeric with no answer' => [['type' => 'numeric', 'numeric_answer' => ''], 'numeric_answer', 'Enter the correct number.'],
    'negative tolerance' => [['type' => 'numeric', 'numeric_tolerance' => '-1'], 'numeric_tolerance', 'The tolerance field must be at least 0.'],
    'short text with no answers' => [['type' => 'short_text', 'accepted_answers' => "\n"], 'accepted_answers', 'Add at least one accepted answer.'],
    'year group outside the key stage' => [['year_group' => '8'], 'year_group', "Year 8 isn't in KS2. Choose a KS2 year or leave it blank."],
    'unknown exam track' => [['exam_tracks' => ['a-level']], 'exam_tracks.0', 'The selected exam track is invalid.'],
    'no stem' => [['stem' => '   '], 'stem', 'The question field is required.'],
]);

it('creates a draft with its options and exam tracks', function () {
    $data = QuestionInput::normalise(questionInput(['exam_tracks' => ['sats', 'eleven_plus']]));
    $question = app(SaveQuestion::class)->handle($data, $this->admin);

    expect($question->status)->toBe(QuestionStatus::Draft)
        ->and($question->author_id)->toBe($this->admin->id)
        ->and($question->options->pluck('body')->all())->toBe(['4', '2', '8'])
        ->and($question->options->pluck('is_correct')->all())->toBe([true, false, false])
        ->and($question->examTracks())->toBe([ExamTrack::ElevenPlus, ExamTrack::Sats])
        ->and($question->numeric_answer)->toBeNull();
});

it('keeps option ids by position when editing and removes options when the type changes', function () {
    $save = app(SaveQuestion::class);
    $question = $save->handle(QuestionInput::normalise(questionInput()), $this->admin);
    $firstId = $question->options->first()->id;

    $question = $save->handle(QuestionInput::normalise(questionInput(['options' => ['5', '6'], 'correct' => ['1'], 'exam_tracks' => []])), $this->admin, $question);

    expect($question->fresh()->options->pluck('body')->all())->toBe(['5', '6'])
        ->and($question->fresh()->options->first()->id)->toBe($firstId)
        ->and($question->fresh()->examTracks())->toBe([]);

    $save->handle(QuestionInput::normalise(questionInput(['type' => 'numeric', 'numeric_answer' => '4'])), $this->admin, $question);

    expect($question->fresh()->type)->toBe(QuestionType::Numeric)
        ->and($question->fresh()->options)->toHaveCount(0)
        ->and((float) $question->fresh()->numeric_answer)->toBe(4.0);
});

it('stores, replaces and removes the diagram', function () {
    Storage::fake('public');
    $save = app(SaveQuestion::class);
    $data = QuestionInput::normalise(questionInput());

    $question = $save->handle($data, $this->admin, image: UploadedFile::fake()->image('first.png'));
    $first = $question->image_path;
    Storage::disk('public')->assertExists($first);

    $question = $save->handle($data, $this->admin, $question, UploadedFile::fake()->image('second.png'));
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($question->image_path);

    $second = $question->image_path;
    $question = $save->handle($data, $this->admin, $question, removeImage: true);
    expect($question->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);
});
