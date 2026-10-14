<?php

namespace App\Actions;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\AttendanceRecord;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place attendance is written. Callers say whether someone attended
 * (Present + arrival time), was Absent or Excused; lateness is worked out here
 * and the person's lateness report is created, updated or removed to match.
 * Students and tutors follow exactly the same rules.
 */
class RecordAttendance
{
    /**
     * With $onlyIfUnrecorded, an existing record is returned untouched (used by check-in and the no-show job).
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        Lesson $lesson,
        User $attendee,
        AttendanceStatus $status,
        ?User $recordedBy,
        ?CarbonImmutable $arrivedAt = null,
        ?string $notes = null,
        bool $onlyIfUnrecorded = false,
    ): AttendanceRecord {
        if ($status === AttendanceStatus::Late) {
            throw new InvalidArgumentException('Record Present with an arrival time; lateness is worked out from it.');
        }

        $attendeeRole = $this->attendeeRoleFor($lesson, $attendee);

        $arrivedAt = $status === AttendanceStatus::Present ? ($arrivedAt ?? $lesson->starts_at) : null;
        $minutesLate = $arrivedAt === null ? 0 : max(0, (int) floor($lesson->starts_at->diffInSeconds($arrivedAt, false) / 60));
        $isLate = $arrivedAt !== null && $minutesLate > (int) config('academy.lateness_grace_minutes');

        return DB::transaction(function () use ($lesson, $attendee, $attendeeRole, $status, $recordedBy, $arrivedAt, $minutesLate, $isLate, $notes, $onlyIfUnrecorded): AttendanceRecord {
            // Serialise writers for this lesson so check-ins, saves and the no-show job can't interleave.
            Lesson::query()->whereKey($lesson->id)->lockForUpdate()->first();

            if ($onlyIfUnrecorded) {
                $existing = AttendanceRecord::query()->where('lesson_id', $lesson->id)->where('user_id', $attendee->id)->first();

                if ($existing !== null) {
                    return $existing->load('latenessReport');
                }
            }

            $record = AttendanceRecord::query()->updateOrCreate(
                ['lesson_id' => $lesson->id, 'user_id' => $attendee->id],
                [
                    'attendee_role' => $attendeeRole,
                    'status' => $isLate ? AttendanceStatus::Late : $status,
                    'arrived_at' => $arrivedAt,
                    'minutes_late' => $isLate ? $minutesLate : null,
                    'recorded_by_id' => $recordedBy?->id,
                    'notes' => $notes,
                ],
            );

            $this->syncLatenessReport($record, $lesson);

            return $record->load('latenessReport');
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function attendeeRoleFor(Lesson $lesson, User $attendee): AttendeeRole
    {
        if ($attendee->id === $lesson->tutor_id) {
            return AttendeeRole::Tutor;
        }

        if ($lesson->classGroup->students()->withTrashed()->whereKey($attendee->id)->exists()) {
            return AttendeeRole::Student;
        }

        throw new InvalidArgumentException('Only the lesson\'s tutor and the class\'s students can be recorded.');
    }

    private function syncLatenessReport(AttendanceRecord $record, Lesson $lesson): void
    {
        if ($record->status !== AttendanceStatus::Late) {
            $report = LatenessReport::query()->where('attendance_record_id', $record->id)->first();

            // The lateness no longer stands, so parents shouldn't keep reading about it.
            $report?->guardianNotices()->whereNull('acknowledged_at')->delete();
            $report?->delete();

            return;
        }

        LatenessReport::query()->updateOrCreate(
            ['attendance_record_id' => $record->id],
            [
                'user_id' => $record->user_id,
                'attendee_role' => $record->attendee_role,
                'lesson_id' => $lesson->id,
                'class_name' => $lesson->classGroup->name,
                'lesson_date' => $lesson->localStart()->toDateString(),
                'scheduled_start_at' => $lesson->starts_at,
                'arrived_at' => $record->arrived_at,
                'minutes_late' => $record->minutes_late,
            ],
        );
    }
}
