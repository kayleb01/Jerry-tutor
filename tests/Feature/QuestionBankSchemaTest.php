<?php

use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Strand;
use App\Models\Topic;
use Database\Seeders\CurriculumSeeder;

it('seeds four strands and topics for every key stage and strand, once', function () {
    $this->seed(CurriculumSeeder::class);
    $this->seed(CurriculumSeeder::class);

    expect(Strand::orderBy('sort_order')->pluck('slug')->all())->toBe(['number', 'algebra', 'statistics', 'geometry']);

    foreach (KeyStage::cases() as $keyStage) {
        foreach (Strand::all() as $strand) {
            expect(Topic::where('key_stage', $keyStage)->where('strand_id', $strand->id)->count())
                ->toBeGreaterThan(0, "{$keyStage->label()} {$strand->name} has no topics");
        }
    }

    expect(Topic::count())->toBe(collect(CurriculumSeeder::TOPICS)->flatten(2)->count());
});

it('allows only the agreed status transitions', function (QuestionStatus $from, QuestionStatus $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    'draft to review' => [QuestionStatus::Draft, QuestionStatus::InReview, true],
    'draft to published' => [QuestionStatus::Draft, QuestionStatus::Published, false],
    'draft to retired' => [QuestionStatus::Draft, QuestionStatus::Retired, false],
    'review to draft' => [QuestionStatus::InReview, QuestionStatus::Draft, true],
    'review to published' => [QuestionStatus::InReview, QuestionStatus::Published, true],
    'published to retired' => [QuestionStatus::Published, QuestionStatus::Retired, true],
    'published to draft' => [QuestionStatus::Published, QuestionStatus::Draft, false],
    'retired to draft' => [QuestionStatus::Retired, QuestionStatus::Draft, true],
    'retired to published' => [QuestionStatus::Retired, QuestionStatus::Published, false],
]);

it('only lets drafts and questions in review be deleted', function () {
    expect(collect(QuestionStatus::cases())->filter->isDeletable()->values()->all())
        ->toBe([QuestionStatus::Draft, QuestionStatus::InReview]);
});

it('builds factory questions with options and exam tracks', function () {
    $choice = Question::factory()->create();
    $multi = Question::factory()->multiSelect()->create();
    $numeric = Question::factory()->numeric(2.5, 0.1)->create();
    $choice->examTrackRows()->create(['exam_track' => ExamTrack::Sats]);

    expect($choice->status)->toBe(QuestionStatus::Draft)
        ->and($choice->type)->toBe(QuestionType::MultipleChoice)
        ->and($choice->options)->toHaveCount(4)
        ->and($choice->options->where('is_correct', true))->toHaveCount(1)
        ->and($choice->examTracks())->toBe([ExamTrack::Sats])
        ->and($multi->options->where('is_correct', true))->toHaveCount(2)
        ->and($numeric->options)->toHaveCount(0)
        ->and((float) $numeric->numeric_answer)->toBe(2.5);
});

it('scopes to published questions and leaves out soft-deleted ones', function () {
    $published = Question::factory()->published()->create();
    Question::factory()->published()->create()->delete();
    Question::factory()->inReview()->create();
    Question::factory()->retired()->create();

    expect(Question::published()->pluck('id')->all())->toBe([$published->id]);
});
