<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns each class's weekly schedule into dated lessons. The schedule is read in
 * the academy timezone; lessons are stored in UTC.
 */
class LessonScheduler
{
    /**
     * Generate upcoming lessons for every active class that has a schedule and a tutor.
     */
    public function generate(?int $days = null): int
    {
        return ClassGroup::query()
            ->where('is_active', true)
            ->whereNotNull('weekday')
            ->whereNotNull('start_time')
            ->whereHas('tutor')
            ->lazyById()
            ->sum(fn (ClassGroup $group): int => $this->generateFor($group, $days));
    }

    /**
     * Generate the class's upcoming lessons that don't exist yet. Returns how many were created.
     * The class is re-read and locked first, so a stale copy can never recreate lessons at an old time.
     */
    public function generateFor(ClassGroup $group, ?int $days = null): int
    {
        return DB::transaction(function () use ($group, $days): int {
            $group = ClassGroup::query()->lockForUpdate()->find($group->id);

            if ($group === null || ! $group->is_active || ! $group->hasSchedule() || $group->tutor === null) {
                return 0;
            }

            return $this->createUpcomingLessons($group, $days);
        });
    }

    private function createUpcomingLessons(ClassGroup $group, ?int $days): int
    {

        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($timezone)->startOfDay();
        $days ??= (int) config('academy.lesson_horizon_days');
        [$hour, $minute] = array_map('intval', explode(':', $group->start_time));
        $created = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $today->addDays($offset);

            if ($date->dayOfWeekIso !== $group->weekday->value) {
                continue;
            }

            $startsAt = $date->setTime($hour, $minute)->utc();

            if ($startsAt->lessThanOrEqualTo($now)) {
                continue;
            }

            $lesson = Lesson::query()->firstOrCreate(
                ['class_group_id' => $group->id, 'starts_at' => $startsAt],
                [
                    'tutor_id' => $group->tutor_id,
                    'ends_at' => $startsAt->addMinutes($group->duration_minutes),
                    'status' => LessonStatus::Scheduled,
                ],
            );

            $created += $lesson->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * Replace the class's not-yet-started scheduled lessons after its schedule, tutor or status changed.
     */
    public function rescheduleFuture(ClassGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            ClassGroup::query()->lockForUpdate()->find($group->id);

            $group->lessons()
                ->where('status', LessonStatus::Scheduled)
                ->where('starts_at', '>', CarbonImmutable::now())
                ->delete();

            $this->generateFor($group);
        });
    }
}
