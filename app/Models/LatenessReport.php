<?php

namespace App\Models;

use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot created whenever someone is late, so the history survives schedule changes.
 * Only App\Actions\RecordAttendance writes these.
 *
 * @property int $id
 * @property int $attendance_record_id
 * @property int $user_id
 * @property AttendeeRole $attendee_role
 * @property int $lesson_id
 * @property string $class_name
 * @property CarbonImmutable $lesson_date
 * @property CarbonImmutable $scheduled_start_at
 * @property CarbonImmutable $arrived_at
 * @property int $minutes_late
 * @property string|null $reason
 * @property int|null $reviewed_by_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $follow_up_notes
 */
#[Fillable(['attendance_record_id', 'user_id', 'attendee_role', 'lesson_id', 'class_name', 'lesson_date', 'scheduled_start_at', 'arrived_at', 'minutes_late', 'reason', 'reviewed_by_id', 'reviewed_at', 'follow_up_notes'])]
class LatenessReport extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendee_role' => AttendeeRole::class,
            'lesson_date' => 'immutable_date',
            'scheduled_start_at' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
            'minutes_late' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id')->withTrashed();
    }
}
