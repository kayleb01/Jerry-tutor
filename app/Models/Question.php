<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\YearGroup;
use Carbon\CarbonImmutable;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One question in the bank. Only published questions are ever served to students.
 *
 * @property int $id
 * @property int $topic_id
 * @property Difficulty $difficulty
 * @property YearGroup|null $year_group
 * @property QuestionType $type
 * @property string $stem
 * @property string|null $image_path
 * @property string|null $numeric_answer
 * @property string $numeric_tolerance
 * @property array<int, string>|null $accepted_answers
 * @property string|null $explanation
 * @property int $marks
 * @property QuestionStatus $status
 * @property int|null $author_id
 * @property int|null $reviewed_by_id
 * @property CarbonImmutable|null $published_at
 */
#[Fillable(['topic_id', 'difficulty', 'year_group', 'type', 'stem', 'image_path', 'numeric_answer', 'numeric_tolerance', 'accepted_answers', 'explanation', 'marks', 'status', 'author_id', 'reviewed_by_id', 'published_at'])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'difficulty' => Difficulty::class,
            'year_group' => YearGroup::class,
            'type' => QuestionType::class,
            'status' => QuestionStatus::class,
            'numeric_answer' => 'decimal:4',
            'numeric_tolerance' => 'decimal:4',
            'accepted_answers' => 'array',
            'marks' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<QuestionExamTrack, $this>
     */
    public function examTrackRows(): HasMany
    {
        return $this->hasMany(QuestionExamTrack::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id')->withTrashed();
    }

    /**
     * The question's exam tracks in their enum order.
     *
     * @return array<int, ExamTrack>
     */
    public function examTracks(): array
    {
        $order = array_flip(array_map(fn (ExamTrack $track): string => $track->value, ExamTrack::cases()));

        return $this->examTrackRows
            ->pluck('exam_track')
            ->sortBy(fn (ExamTrack $track): int => $order[$track->value])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', QuestionStatus::Published);
    }
}
