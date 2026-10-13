<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated occurrence of a ClassGroup. Times are UTC.
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $tutor_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property LessonStatus $status
 * @property string|null $cancelled_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['class_group_id', 'tutor_id', 'starts_at', 'ends_at', 'status', 'cancelled_reason'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => LessonStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * Includes deactivated tutors, so past lessons keep their history.
     *
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id')->withTrashed();
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * @return HasMany<LatenessReport, $this>
     */
    public function latenessReports(): HasMany
    {
        return $this->hasMany(LatenessReport::class);
    }

    public function localStart(): CarbonImmutable
    {
        return $this->starts_at->setTimezone(config('academy.timezone'));
    }

    public function localEnd(): CarbonImmutable
    {
        return $this->ends_at->setTimezone(config('academy.timezone'));
    }

    /**
     * Read an "HH:MM" clock time on the lesson's local date and return it in UTC.
     */
    public function localTimeToUtc(string $time): CarbonImmutable
    {
        return $this->localStart()->setTimeFromTimeString($time)->utc();
    }

    public function isCancelled(): bool
    {
        return $this->status === LessonStatus::Cancelled;
    }
}
