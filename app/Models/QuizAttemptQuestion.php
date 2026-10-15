<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question as served in an attempt, with the student's answer and, once submitted, the mark.
 *
 * @property int $id
 * @property int $quiz_attempt_id
 * @property int $question_id
 * @property int $position
 * @property array<string, mixed>|null $response
 * @property bool|null $is_correct
 * @property int $marks_awarded
 * @property CarbonImmutable|null $answered_at
 */
#[Fillable(['quiz_attempt_id', 'question_id', 'position', 'response', 'is_correct', 'marks_awarded', 'answered_at'])]
class QuizAttemptQuestion extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_correct' => 'boolean',
            'marks_awarded' => 'integer',
            'position' => 'integer',
            'answered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<QuizAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
    }
}
