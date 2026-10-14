<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RaisedIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note an admin raised with a lesson's tutor from the class reports inbox, shown on the
 * tutor's dashboard until they acknowledge it.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $tutor_id
 * @property int|null $raised_by_id
 * @property string $note
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['lesson_id', 'tutor_id', 'raised_by_id', 'note', 'acknowledged_at'])]
class RaisedIssue extends Model
{
    /** @use HasFactory<RaisedIssueFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id')->withTrashed();
    }
}
