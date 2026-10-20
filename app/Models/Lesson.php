<?php

namespace App\Models;

use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated occurrence of a ClassSlot. It snapshots the slot's tutor and times. Times are UTC.
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $class_slot_id
 * @property int $tutor_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property LessonStatus $status
 * @property string|null $cancelled_reason
 * @property CarbonImmutable|null $reports_reviewed_at when an admin last marked the reports reviewed
 * @property int|null $reports_reviewed_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['class_group_id', 'class_slot_id', 'tutor_id', 'starts_at', 'ends_at', 'status', 'cancelled_reason'])]
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
            'reports_reviewed_at' => 'immutable_datetime',
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
     * Includes removed slots, so past lessons keep their subject.
     *
     * @return BelongsTo<ClassSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(ClassSlot::class, 'class_slot_id')->withTrashed();
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

    /**
     * Submitted reports only; tutor drafts are not visible through this relation.
     *
     * @return HasMany<LessonReport, $this>
     */
    public function lessonReports(): HasMany
    {
        return $this->hasMany(LessonReport::class)->submitted();
    }

    /**
     * @return HasMany<LessonReport, $this>
     */
    public function reportsIncludingDrafts(): HasMany
    {
        return $this->hasMany(LessonReport::class);
    }

    /**
     * @return HasMany<RaisedIssue, $this>
     */
    public function raisedIssues(): HasMany
    {
        return $this->hasMany(RaisedIssue::class);
    }

    /**
     * The admin who last marked this lesson's reports as reviewed.
     *
     * @return BelongsTo<User, $this>
     */
    public function reportsReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_reviewed_by_id')->withTrashed();
    }

    /**
     * "{class} · {subject}", or just the class name when it already contains the subject as a whole word.
     * Reads classGroup and slot.subject, so eager-load them when listing lessons.
     */
    public function title(): string
    {
        $className = $this->classGroup->name;
        $subjectName = $this->slot->subject->name;

        if (preg_match('/\b'.preg_quote($subjectName, '/').'\b/iu', $className) === 1) {
            return $className;
        }

        return $className.' · '.$subjectName;
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

    /**
     * Lessons that need a tutor report: started by $now, not cancelled, no submitted tutor report,
     * someone attended, and the tutor wasn't marked away by a person (see tutorWasAway()).
     *
     * @param  Builder<Lesson>  $query
     */
    #[Scope]
    protected function needingTutorReport(Builder $query, CarbonImmutable $now): void
    {
        $query
            ->where('lessons.starts_at', '<=', $now->utc())
            ->where('lessons.status', '!=', LessonStatus::Cancelled)
            ->whereDoesntHave('lessonReports', fn (Builder $reports) => $reports->fromTutor())
            ->whereDoesntHave('attendanceRecords', fn (Builder $records) => $records
                ->where('attendance_records.attendee_role', AttendeeRole::Tutor)
                ->whereColumn('attendance_records.user_id', 'lessons.tutor_id')
                ->whereIn('attendance_records.status', AttendanceRecord::AWAY)
                ->personEntered())
            ->whereHas('attendanceRecords', fn (Builder $records) => $records->attended());
    }

    /**
     * A person marked the lesson's tutor Absent or Excused, so no tutor report is owed. An automatic
     * absence from the no-show job doesn't count: the tutor may have taught without checking in.
     * Reads the loaded attendanceRecords; the query form is needingTutorReport().
     */
    public function tutorWasAway(): bool
    {
        return $this->attendanceRecords->contains(fn (AttendanceRecord $record): bool => $record->attendee_role === AttendeeRole::Tutor
            && $record->user_id === $this->tutor_id
            && in_array($record->status, AttendanceRecord::AWAY, true)
            && $record->isPersonEntered());
    }
}
