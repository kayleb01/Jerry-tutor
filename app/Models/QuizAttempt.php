<?php

namespace App\Models;

use App\Enums\QuizAttemptStatus;
use Carbon\CarbonImmutable;
use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student's go at a quiz, with the questions drawn for it.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 * @property QuizAttemptStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $score
 * @property int $max_score
 * @property int|null $duration_seconds
 */
#[Fillable(['user_id', 'quiz_id', 'status', 'started_at', 'submitted_at', 'score', 'max_score', 'duration_seconds'])]
class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuizAttemptStatus::class,
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'score' => 'integer',
            'max_score' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return HasMany<QuizAttemptQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizAttemptQuestion::class)->orderBy('position');
    }

    /**
     * The score as a whole percentage, once submitted.
     */
    public function percentage(): ?int
    {
        return $this->score === null || $this->max_score === 0 ? null : (int) round($this->score / $this->max_score * 100);
    }
}
