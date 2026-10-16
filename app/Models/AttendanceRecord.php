<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /** Statuses meaning the person was there. */
    public const ATTENDED = [AttendanceStatus::Present, AttendanceStatus::Late];

    /** Statuses meaning the person was not there. */
    public const AWAY = [AttendanceStatus::Absent, AttendanceStatus::Excused];

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

    /**
     * The note telling the student's parents about this absence, if one was sent.
     *
     * @return HasOne<GuardianNotice, $this>
     */
    public function absenceNotice(): HasOne
    {
        return $this->hasOne(GuardianNotice::class);
    }

    /**
     * Records a person entered. The no-show job's automatic absences leave recorded_by_id null and are not evidence.
     *
     * @param  Builder<AttendanceRecord>  $query
     */
    #[Scope]
    protected function personEntered(Builder $query): void
    {
        $query->whereNotNull('attendance_records.recorded_by_id');
    }

    /**
     * Someone was actually there (Present or Late).
     *
     * @param  Builder<AttendanceRecord>  $query
     */
    #[Scope]
    protected function attended(Builder $query): void
    {
        $query->whereIn('attendance_records.status', self::ATTENDED);
    }

    public function isPersonEntered(): bool
    {
        return $this->recorded_by_id !== null;
    }
}
