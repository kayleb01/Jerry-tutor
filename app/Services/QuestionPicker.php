<?php

namespace App\Services;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\YearGroup;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Chooses a quiz's questions for one student: questions they've never seen first, so nothing repeats
 * until the pool is used up; then the ones they last got wrong; then the ones seen longest ago.
 */
class QuestionPicker
{
    /**
     * Published questions that fit the quiz recipe.
     *
     * @return Builder<Question>
     */
    public function pool(Quiz $quiz): Builder
    {
        return Question::query()
            ->published()
            ->when($quiz->difficulty, fn (Builder $query, Difficulty $difficulty) => $query->where('questions.difficulty', $difficulty))
            ->when($quiz->year_group, fn (Builder $query, YearGroup $year) => $query->where(fn (Builder $query) => $query
                ->whereNull('questions.year_group')
                ->orWhere('questions.year_group', $year)))
            ->when($quiz->topic_id, fn (Builder $query, int $topic) => $query->where('questions.topic_id', $topic))
            ->when($quiz->key_stage || $quiz->strand_id, fn (Builder $query) => $query->whereHas('topic', fn (Builder $topic) => $topic
                ->when($quiz->key_stage, fn (Builder $topic, KeyStage $keyStage) => $topic->where('key_stage', $keyStage))
                ->when($quiz->strand_id, fn (Builder $topic, int $strand) => $topic->where('strand_id', $strand))))
            ->when($quiz->exam_track, fn (Builder $query, ExamTrack $track) => $query->whereHas('examTrackRows', fn (Builder $rows) => $rows->where('exam_track', $track)));
    }

    public function poolSize(Quiz $quiz): int
    {
        return $this->pool($quiz)->count();
    }

    /**
     * @return Collection<int, Question>
     */
    public function pick(Quiz $quiz, User $student, ?int $count = null): Collection
    {
        $count ??= $quiz->question_count;

        $unseen = $this->pool($quiz)
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->selectRaw('1')
                ->from('question_exposures')
                ->whereColumn('question_exposures.question_id', 'questions.id')
                ->where('question_exposures.user_id', $student->id))
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($unseen->count() >= $count) {
            return $unseen;
        }

        $repeats = $this->pool($quiz)
            ->select('questions.*')
            ->join('question_exposures', fn (JoinClause $join) => $join
                ->on('question_exposures.question_id', '=', 'questions.id')
                ->where('question_exposures.user_id', '=', $student->id))
            ->orderByRaw('CASE WHEN question_exposures.last_correct = ? THEN 0 ELSE 1 END', [false])
            ->orderBy('question_exposures.last_seen_at')
            ->orderBy('questions.id')
            ->limit($count - $unseen->count())
            ->get();

        return $unseen->concat($repeats)->values();
    }
}
