<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\YearGroup;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A group of students, e.g. "Year 5". Its weekly timetable is a set of ClassSlots, each with
 * its own subject and tutor; each dated occurrence of a slot is a Lesson.
 *
 * @property int $id
 * @property string $name
 * @property YearGroup|null $year_group
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'year_group', 'is_active'])]
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * The class's timetable; removed slots are excluded.
     *
     * @return HasMany<ClassSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ClassSlot::class);
    }

    /**
     * Classes where the tutor teaches at least one (non-removed) slot.
     *
     * @param  Builder<ClassGroup>  $query
     */
    #[Scope]
    protected function taughtBy(Builder $query, User $tutor): void
    {
        $query->whereHas('slots', fn (Builder $slots) => $slots->where('tutor_id', $tutor->id));
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
}
