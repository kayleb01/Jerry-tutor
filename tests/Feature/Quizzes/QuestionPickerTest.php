<?php

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\YearGroup;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Strand;
use App\Models\Topic;
use App\Models\User;
use App\Services\QuestionPicker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->number = Strand::factory()->create(['slug' => 'number']);
    $this->algebra = Strand::factory()->create(['slug' => 'algebra']);
    $this->fractions = Topic::factory()->for($this->number)->create(['key_stage' => KeyStage::Ks2]);
    $this->patterns = Topic::factory()->for($this->algebra)->create(['key_stage' => KeyStage::Ks2]);
    $this->ks3Topic = Topic::factory()->for($this->number)->create(['key_stage' => KeyStage::Ks3]);
    $this->student = User::factory()->student()->create();
    $this->picker = app(QuestionPicker::class);
});

function bankQuestion(Topic $topic, array $attributes = []): Question
{
    return Question::factory()->published()->for($topic)->create(['difficulty' => Difficulty::Intermediate, 'year_group' => null, ...$attributes]);
}

function seen(User $student, Question $question, string $when, ?bool $correct = null): void
{
    DB::table('question_exposures')->insert(['user_id' => $student->id, 'question_id' => $question->id, 'times_seen' => 1, 'last_seen_at' => CarbonImmutable::parse($when, 'UTC'), 'last_correct' => $correct]);
}

it('only picks published questions that match the recipe', function () {
    $quiz = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'year_group' => YearGroup::Year5, 'difficulty' => Difficulty::Intermediate, 'strand_id' => $this->number->id]);
    $forYear5 = bankQuestion($this->fractions, ['year_group' => YearGroup::Year5]);
    $wholeKeyStage = bankQuestion($this->fractions);

    bankQuestion($this->fractions, ['year_group' => YearGroup::Year6]);
    bankQuestion($this->fractions, ['difficulty' => Difficulty::Hard]);
    bankQuestion($this->patterns);
    bankQuestion($this->ks3Topic);
    Question::factory()->for($this->fractions)->create(['difficulty' => Difficulty::Intermediate]);
    Question::factory()->inReview()->for($this->fractions)->create(['difficulty' => Difficulty::Intermediate]);
    Question::factory()->retired()->for($this->fractions)->create(['difficulty' => Difficulty::Intermediate]);
    bankQuestion($this->fractions)->delete();

    expect($this->picker->pick($quiz, $this->student)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$forYear5->id, $wholeKeyStage->id])->sort()->values()->all())
        ->and($this->picker->poolSize($quiz))->toBe(2);
});

it('filters by exam track and topic', function () {
    $sats = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'difficulty' => null, 'exam_track' => ExamTrack::Sats]);
    $tagged = bankQuestion($this->patterns, ['difficulty' => Difficulty::Hard]);
    $tagged->examTrackRows()->create(['exam_track' => ExamTrack::Sats]);
    bankQuestion($this->patterns);

    $topicQuiz = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'difficulty' => null, 'topic_id' => $this->fractions->id]);
    $fraction = bankQuestion($this->fractions);

    expect($this->picker->pick($sats, $this->student)->pluck('id')->all())->toBe([$tagged->id])
        ->and($this->picker->pick($topicQuiz, $this->student)->pluck('id')->all())->toBe([$fraction->id]);
});

it('serves unseen questions before any repeats', function () {
    $quiz = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'difficulty' => Difficulty::Intermediate, 'question_count' => 3]);
    $questions = collect(range(1, 5))->map(fn () => bankQuestion($this->fractions));
    seen($this->student, $questions[0], '2026-10-14 10:00:00', true);
    seen($this->student, $questions[1], '2026-10-14 11:00:00', true);

    $picked = $this->picker->pick($quiz, $this->student)->pluck('id');

    expect($picked)->toHaveCount(3)
        ->and($picked->sort()->values()->all())->toBe($questions->slice(2)->pluck('id')->sort()->values()->all());
});

it('tops up with questions answered wrongly first, then the least recently seen', function () {
    $quiz = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'difficulty' => Difficulty::Intermediate, 'question_count' => 4]);
    [$unseen, $rightOld, $wrongRecent, $rightRecent, $rightOldest] = collect(range(1, 5))->map(fn () => bankQuestion($this->fractions))->all();
    seen($this->student, $rightOld, '2026-10-10 10:00:00', true);
    seen($this->student, $wrongRecent, '2026-10-14 10:00:00', false);
    seen($this->student, $rightRecent, '2026-10-14 11:00:00', true);
    seen($this->student, $rightOldest, '2026-10-01 10:00:00', true);

    expect($this->picker->pick($quiz, $this->student)->pluck('id')->all())
        ->toBe([$unseen->id, $wrongRecent->id, $rightOldest->id, $rightOld->id]);
});

it('only counts this student\'s exposures', function () {
    $quiz = Quiz::factory()->create(['key_stage' => KeyStage::Ks2, 'difficulty' => Difficulty::Intermediate, 'question_count' => 1]);
    $question = bankQuestion($this->fractions);
    seen(User::factory()->student()->create(), $question, '2026-10-14 10:00:00', true);
    $other = bankQuestion($this->fractions);
    seen($this->student, $other, '2026-10-14 10:00:00', true);

    expect($this->picker->pick($quiz, $this->student)->pluck('id')->all())->toBe([$question->id]);
});
