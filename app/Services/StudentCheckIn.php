<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Finds the lesson a student can check in to right now and describes their check-in, in academy time.
 */
class StudentCheckIn
{
    /**
     * The earliest-starting lesson the student may check in to now, or null.
     */
    public function openLessonFor(User $student, ?CarbonImmutable $now = null): ?Lesson
    {
        $now ??= CarbonImmutable::now();

        return Lesson::query()
            ->whereIn('class_group_id', $this->activeClassIds($student))
            ->where('status', '!=', LessonStatus::Cancelled)
            ->where('starts_at', '<=', $now->addMinutes(30)->utc())
            ->where('ends_at', '>=', $now->utc())
            ->with(['classGroup', 'slot.subject'])
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * How the student's attendance for this lesson is recorded, or null when nothing is recorded yet.
     *
     * @return array{time: string|null, status: string, minutesLate: int|null, recordedBySelf: bool}|null
     */
    public function stamp(Lesson $lesson, User $student): ?array
    {
        $record = AttendanceRecord::query()
            ->where('lesson_id', $lesson->id)
            ->where('user_id', $student->id)
            ->first();

        if ($record === null) {
            return null;
        }

        return [
            'time' => $record->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i'),
            'status' => $record->status->value,
            'minutesLate' => $record->minutes_late,
            'recordedBySelf' => $record->recorded_by_id === $student->id,
        ];
    }

    /**
     * The lesson open for check-in with the student's stamp, or null when nothing is open.
     *
     * @return array{lessonId: int, title: string, startsAt: string, stamp: array{time: string|null, status: string, minutesLate: int|null, recordedBySelf: bool}|null}|null
     */
    public function card(User $student): ?array
    {
        $lesson = $this->openLessonFor($student);

        if ($lesson === null) {
            return null;
        }

        return [
            'lessonId' => $lesson->id,
            'title' => $lesson->title(),
            'startsAt' => $lesson->localStart()->format('H:i'),
            'stamp' => $this->stamp($lesson, $student),
        ];
    }

    /**
     * e.g. "Tue 09:00 · Year 5 · Maths" for the student's next lesson that hasn't started, or null.
     */
    public function nextLessonLabel(User $student): ?string
    {
        $lesson = Lesson::query()
            ->whereIn('class_group_id', $this->activeClassIds($student))
            ->where('status', '!=', LessonStatus::Cancelled)
            ->where('starts_at', '>', CarbonImmutable::now()->utc())
            ->with(['classGroup', 'slot.subject'])
            ->orderBy('starts_at')
            ->first();

        return $lesson === null ? null : $lesson->localStart()->format('D H:i').' · '.$lesson->title();
    }

    /**
     * @return array<int, int>
     */
    private function activeClassIds(User $student): array
    {
        return $student->enrolledClassGroups()->wherePivotNull('left_at')->pluck('class_groups.id')->all();
    }
}
