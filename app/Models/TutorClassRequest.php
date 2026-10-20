<?php

namespace App\Models;

use Database\Factories\TutorClassRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A class and subject a tutor applicant said they would like to teach.
 *
 * @property int $id
 * @property int $tutor_id
 * @property int $class_group_id
 * @property int $subject_id
 */
#[Fillable(['tutor_id', 'class_group_id', 'subject_id'])]
class TutorClassRequest extends Model
{
    /** @use HasFactory<TutorClassRequestFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
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
     * e.g. "Year 5 · Science", named like the lessons it would become. Reads classGroup and subject.
     */
    public function label(): string
    {
        return Lesson::titleFor($this->classGroup->name, $this->subject->name);
    }
}
