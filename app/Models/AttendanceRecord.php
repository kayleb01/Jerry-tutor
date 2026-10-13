<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Whether one person (student or tutor) attended one lesson, and when they arrived.
 * Only App\Actions\RecordAttendance writes these.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $user_id
 * @property AttendeeRole $attendee_role
 * @property AttendanceStatus $status
 * @property CarbonImmutable|null $arrived_at
 * @property int|null $minutes_late
 * @property int|null $recorded_by_id
 * @property string|null $notes
 */
#[Fillable(['lesson_id', 'user_id', 'attendee_role', 'status', 'arrived_at', 'minutes_late', 'recorded_by_id', 'notes'])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendee_role' => AttendeeRole::class,
            'status' => AttendanceStatus::class,
            'arrived_at' => 'immutable_datetime',
            'minutes_late' => 'integer',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id')->withTrashed();
    }

    /**
     * @return HasOne<LatenessReport, $this>
     */
    public function latenessReport(): HasOne
    {
        return $this->hasOne(LatenessReport::class);
    }
}
