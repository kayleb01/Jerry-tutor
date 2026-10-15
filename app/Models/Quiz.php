<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\YearGroup;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quiz recipe: its questions are drawn from the bank when a student starts it.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property KeyStage|null $key_stage
 * @property YearGroup|null $year_group null = any year in the key stage
 * @property ExamTrack|null $exam_track
 * @property Difficulty|null $difficulty null = any difficulty
 * @property int|null $strand_id null = mixed strands
 * @property int|null $topic_id null = mixed topics
 * @property int $question_count
 * @property int|null $time_limit_seconds
 * @property bool $is_published
 * @property int $sort_order
 */
#[Fillable(['title', 'slug', 'key_stage', 'year_group', 'exam_track', 'difficulty', 'strand_id', 'topic_id', 'question_count', 'time_limit_seconds', 'is_published', 'sort_order'])]
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_stage' => KeyStage::class,
            'year_group' => YearGroup::class,
            'exam_track' => ExamTrack::class,
            'difficulty' => Difficulty::class,
            'question_count' => 'integer',
            'time_limit_seconds' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Strand, $this>
     */
    public function strand(): BelongsTo
    {
        return $this->belongsTo(Strand::class);
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
