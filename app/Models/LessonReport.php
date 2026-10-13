<?php

namespace App\Models;

use App\Enums\AttendeeRole;
use Carbon\CarbonImmutable;
use Database\Factories\LessonReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * End-of-class feedback from one person (the tutor or a student who attended).
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $author_id
 * @property AttendeeRole $author_role
 * @property int|null $rating
 * @property string|null $went_well
 * @property string|null $issues
 * @property string|null $recommendations
 * @property CarbonImmutable $submitted_at
 */
#[Fillable(['lesson_id', 'author_id', 'author_role', 'rating', 'went_well', 'issues', 'recommendations', 'submitted_at'])]
class LessonReport extends Model
{
    /** @use HasFactory<LessonReportFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_role' => AttendeeRole::class,
            'rating' => 'integer',
            'submitted_at' => 'immutable_datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
