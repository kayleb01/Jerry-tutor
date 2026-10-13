<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A recurring class, e.g. "Year 5 Maths – Saturday". Each dated occurrence is a Lesson.
 *
 * @property int $id
 * @property string $name
 * @property int|null $tutor_id
 * @property YearGroup|null $year_group
 * @property string|null $location
 * @property Weekday|null $weekday
 * @property string|null $start_time
 * @property int $duration_minutes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'tutor_id', 'year_group', 'location', 'weekday', 'start_time', 'duration_minutes', 'is_active'])]
class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_group' => YearGroup::class,
            'weekday' => Weekday::class,
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Times are kept as "HH:MM" whatever the database returns (SQLite/MySQL may add seconds).
     */
    protected function startTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    /**
     * Every student who has ever been enrolled, including those who have left.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_group_student', 'class_group_id', 'student_id')
            ->withPivot(['enrolled_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function activeStudents(): BelongsToMany
    {
        return $this->students()->wherePivotNull('left_at');
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * Enrol a student, or re-enrol one who previously left.
     *
     * @throws InvalidArgumentException
     */
    public function enrol(User $student): void
    {
        if (! $student->hasRole(Role::Student)) {
            throw new InvalidArgumentException('Only students can be enrolled in a class.');
        }

        $pivot = ['enrolled_at' => now()->toDateString(), 'left_at' => null];

        if ($this->students()->whereKey($student->id)->exists()) {
            $this->students()->updateExistingPivot($student->id, $pivot);

            return;
        }

        $this->students()->attach($student->id, $pivot);
    }

    /**
     * Mark a student as having left, keeping their enrolment history.
     */
    public function withdraw(User $student): void
    {
        $this->students()->updateExistingPivot($student->id, ['left_at' => now()->toDateString()]);
    }

    public function hasSchedule(): bool
    {
        return $this->weekday !== null && $this->start_time !== null;
    }

    public function scheduleLabel(): string
    {
        if (! $this->hasSchedule()) {
            return 'No regular schedule';
        }

        return "{$this->weekday->plural()} at {$this->start_time} · {$this->duration_minutes} min";
    }
}
