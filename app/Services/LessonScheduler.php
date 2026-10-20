<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\ClassSlot;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns each class slot's weekly time into dated lessons. The time is read in
 * the academy timezone; lessons are stored in UTC.
 */
class LessonScheduler
{
    /**
     * Generate upcoming lessons for every scheduled slot with a tutor, in an active class.
     */
    public function generate(?int $days = null): int
    {
        return ClassSlot::query()
            ->whereNotNull('weekday')
            ->whereNotNull('start_time')
            ->whereHas('classGroup', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('tutor', fn (Builder $query) => $query->withoutTrashed())
            ->lazyById()
            ->sum(fn (ClassSlot $slot): int => $this->generateFor($slot, $days));
    }

    /**
     * Generate the slot's upcoming lessons that don't exist yet. Returns how many were created.
     * The slot is re-read and locked first, so a stale copy can never recreate lessons at an old time.
     */
    public function generateFor(ClassSlot $slot, ?int $days = null): int
    {
        return DB::transaction(function () use ($slot, $days): int {
            $slot = ClassSlot::query()->with('classGroup')->lockForUpdate()->find($slot->id);

            if ($slot === null || ! $slot->classGroup->is_active || ! $slot->hasSchedule() || $slot->tutor === null || $slot->tutor->trashed()) {
                return 0;
            }

            return $this->createUpcomingLessons($slot, $days);
        });
    }

    private function createUpcomingLessons(ClassSlot $slot, ?int $days): int
    {
        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($timezone)->startOfDay();
        $days ??= (int) config('academy.lesson_horizon_days');
        [$hour, $minute] = array_map('intval', explode(':', $slot->start_time));
        $created = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $today->addDays($offset);

            if ($date->dayOfWeekIso !== $slot->weekday->value) {
                continue;
            }

            $startsAt = $date->setTime($hour, $minute)->utc();

            if ($startsAt->lessThanOrEqualTo($now)) {
                continue;
            }

            $lesson = Lesson::query()->firstOrCreate(
                ['class_slot_id' => $slot->id, 'starts_at' => $startsAt],
                [
                    'class_group_id' => $slot->class_group_id,
                    'tutor_id' => $slot->tutor_id,
                    'ends_at' => $startsAt->addMinutes($slot->duration_minutes),
                    'status' => LessonStatus::Scheduled,
                ],
            );

            $created += $lesson->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * Replace the slot's not-yet-started scheduled lessons that have no attendance yet, after its
     * time, tutor or class status changed.
     */
    public function rescheduleFuture(ClassSlot $slot): void
    {
        DB::transaction(function () use ($slot): void {
            $this->removeFuture($slot);
            $this->generateFor($slot);
        });
    }

    /**
     * Delete the slot's not-yet-started scheduled lessons that have no attendance yet, e.g. when the slot is removed.
     */
    public function removeFuture(ClassSlot $slot): void
    {
        DB::transaction(function () use ($slot): void {
            ClassSlot::withTrashed()->lockForUpdate()->find($slot->id);

            $slot->lessons()
                ->where('status', LessonStatus::Scheduled)
                ->where('starts_at', '>', CarbonImmutable::now())
                ->whereDoesntHave('attendanceRecords')
                ->delete();
        });
    }
}
