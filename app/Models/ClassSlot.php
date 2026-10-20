<?php

namespace App\Models;

use App\Enums\Weekday;
use Carbon\CarbonImmutable;
use Database\Factories\ClassSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One weekly timetable slot of a class: a subject taught by a tutor at a day and time.
 * Each dated occurrence is a Lesson.
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $subject_id
 * @property int|null $tutor_id
 * @property Weekday|null $weekday
 * @property string|null $start_time
 * @property int $duration_minutes
 * @property string|null $location
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['class_group_id', 'subject_id', 'tutor_id', 'weekday', 'start_time', 'duration_minutes', 'location'])]
class ClassSlot extends Model
{
    /** @use HasFactory<ClassSlotFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * Read as "HH:MM"; stored as "HH:MM:00" so dirty checks match what MySQL and SQLite return.
     */
    protected function startTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
            set: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5).':00',
        );
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Includes deactivated tutors; generation checks for an active one itself.
     *
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id')->withTrashed();
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function hasSchedule(): bool
    {
        return $this->weekday !== null && $this->start_time !== null;
    }

    /**
     * e.g. "Mon 09:00–11:00", in the academy timezone like the stored time.
     */
    public function timeLabel(): string
    {
        if (! $this->hasSchedule()) {
            return 'No regular time';
        }

        $start = CarbonImmutable::createFromFormat('H:i', $this->start_time);

        return "{$this->weekday->shortLabel()} {$start->format('H:i')}–{$start->addMinutes($this->duration_minutes)->format('H:i')}";
    }
}
