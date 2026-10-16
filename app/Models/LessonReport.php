<?php

namespace App\Models;

use App\Enums\AttendeeRole;
use App\Enums\RecommendedAction;
use Carbon\CarbonImmutable;
use Database\Factories\LessonReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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
 * @property Collection<int, RecommendedAction>|null $recommended_actions
 * @property bool $raises_issue
 * @property CarbonImmutable|null $submitted_at
 */
#[Fillable(['lesson_id', 'author_id', 'author_role', 'rating', 'went_well', 'issues', 'recommendations', 'recommended_actions', 'raises_issue', 'submitted_at'])]
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
            'recommended_actions' => AsEnumCollection::of(RecommendedAction::class),
            'raises_issue' => 'boolean',
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

    /**
     * Reports that have been handed in, as opposed to a tutor's saved drafts.
     *
     * @param  Builder<LessonReport>  $query
     */
    #[Scope]
    protected function submitted(Builder $query): void
    {
        $query->whereNotNull('lesson_reports.submitted_at');
    }

    /**
     * Reports written by the lesson's tutor (as opposed to its students).
     *
     * @param  Builder<LessonReport>  $query
     */
    #[Scope]
    protected function fromTutor(Builder $query): void
    {
        $query->where('lesson_reports.author_role', AttendeeRole::Tutor);
    }

    public function isDraft(): bool
    {
        return $this->submitted_at === null;
    }
}
