<?php

namespace App\Policies;

use App\Enums\AttendanceStatus;
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

    /**
     * The tutor, or a student who attended (on time or late), from the start until 48 hours after the end.
     * Admins read reports but never write them.
     */
    public function submitReport(User $user, Lesson $lesson): bool
    {
        $now = CarbonImmutable::now();

        if ($lesson->isCancelled() || $now->lessThan($lesson->starts_at) || $now->greaterThan($lesson->ends_at->addHours(48))) {
            return false;
        }

        if ($lesson->tutor_id === $user->id) {
            return true;
        }

        return $user->hasRole(Role::Student) && $lesson->attendanceRecords()
            ->where('user_id', $user->id)
            ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
            ->exists();
    }

    private function withinTutorWindow(Lesson $lesson): bool
    {
        $now = CarbonImmutable::now();

        return $now->greaterThanOrEqualTo($lesson->starts_at->subMinutes(30))
            && $now->lessThanOrEqualTo($lesson->ends_at->addDay());
    }
}
