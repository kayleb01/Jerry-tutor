<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GuardianNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note from the academy to a student's guardians (e.g. about lateness), shown on every
 * linked guardian's dashboard until one of them acknowledges it.
 *
 * @property int $id
 * @property int $student_id
 * @property int|null $lateness_report_id
 * @property int|null $sent_by_id
 * @property string $note
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $acknowledged_by_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['student_id', 'lateness_report_id', 'sent_by_id', 'note', 'acknowledged_at', 'acknowledged_by_id'])]
class GuardianNotice extends Model
{
    /** @use HasFactory<GuardianNoticeFactory> */
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
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    /**
     * @return BelongsTo<LatenessReport, $this>
     */
    public function latenessReport(): BelongsTo
    {
        return $this->belongsTo(LatenessReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id')->withTrashed();
    }
}
