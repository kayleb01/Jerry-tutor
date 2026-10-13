<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

class LessonPolicy
{
    /**
     * Only the lesson's own tutor checks in, inside their window.
     */
    public function checkIn(User $user, Lesson $lesson): bool
    {
        return ! $lesson->isCancelled()
            && $lesson->tutor_id === $user->id
            && $this->withinTutorWindow($lesson);
    }

    /**
     * The lesson's tutor (inside their window) or any admin (decision D4: admins override).
     */
    public function takeRegister(User $user, Lesson $lesson): bool
    {
        if ($lesson->isCancelled()) {
            return false;
        }

        if ($user->hasRole(Role::Admin)) {
            return true;
        }

        return $lesson->tutor_id === $user->id && $this->withinTutorWindow($lesson);
    }

    /**
     * Tutors record themselves only through check-in; correcting a tutor's record is an admin job.
     */
    public function recordTutorAttendance(User $user, Lesson $lesson): bool
    {
        return ! $lesson->isCancelled() && $user->hasRole(Role::Admin);
    }

    private function withinTutorWindow(Lesson $lesson): bool
    {
        $now = CarbonImmutable::now();

        return $now->greaterThanOrEqualTo($lesson->starts_at->subMinutes(30))
            && $now->lessThanOrEqualTo($lesson->ends_at->addDay());
    }
}
