<?php

namespace App\Services;

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
     * The tutor's lessons that need a report (see Lesson::needingTutorReport()). Newest first.
     *
     * @return Builder<Lesson>
     */
    public function needingReport(User $tutor, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return Lesson::query()
            ->where('lessons.tutor_id', $tutor->id)
            ->needingTutorReport($now)
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

        if ($lesson->lessonReports()->fromTutor()->where('author_id', $tutor->id)->exists()) {
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
