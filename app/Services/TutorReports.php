<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which lessons a tutor still owes an end-of-class report for. Tutors have no deadline.
 */
class TutorReports
{
    /**
     * Started, uncancelled lessons the tutor taught, where someone attended, the tutor was not
     * marked Absent or Excused, and no submitted tutor report exists. Newest first.
     *
     * @return Builder<Lesson>
     */
    public function needingReport(User $tutor, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return Lesson::query()
            ->where('lessons.tutor_id', $tutor->id)
            ->where('lessons.starts_at', '<=', $now)
            ->where('lessons.status', '!=', LessonStatus::Cancelled)
            ->whereDoesntHave('lessonReports', fn (Builder $reports) => $reports->where('author_role', AttendeeRole::Tutor))
            ->whereDoesntHave('attendanceRecords', fn (Builder $records) => $records
                ->where('attendee_role', AttendeeRole::Tutor)
                ->where('user_id', $tutor->id)
                ->whereIn('status', [AttendanceStatus::Absent, AttendanceStatus::Excused]))
            ->whereHas('attendanceRecords', fn (Builder $records) => $records
                ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late]))
            ->orderByDesc('lessons.starts_at');
    }

    /**
     * Whether the tutor may write or edit the report: it still needs one, or they already submitted one.
     */
    public function canReportOn(User $tutor, Lesson $lesson, ?CarbonImmutable $now = null): bool
    {
        if ($lesson->tutor_id !== $tutor->id || $lesson->isCancelled()) {
            return false;
        }

        if ($lesson->lessonReports()->where('author_role', AttendeeRole::Tutor)->where('author_id', $tutor->id)->exists()) {
            return true;
        }

        return $this->needingReport($tutor, $now)->whereKey($lesson->id)->exists();
    }

    /**
     * The class that just ended (or is running) if it needs a report, otherwise the newest outstanding one.
     */
    public function defaultLesson(User $tutor, ?CarbonImmutable $now = null): ?Lesson
    {
        return $this->needingReport($tutor, $now)->first();
    }
}
