<?php

namespace App\Actions;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Mail\AbsenceMail;
use App\Models\AttendanceRecord;
use App\Models\GuardianNotice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tells a student's parents they were marked absent: one note on their dashboards and an email to
 * each parent with an address. Only a register someone saves does this (never the no-show job), and
 * only once per absence.
 */
class AlertGuardiansOfAbsence
{
    public function handle(AttendanceRecord $record, User $sentBy): void
    {
        if ($record->status !== AttendanceStatus::Absent || $record->attendee_role !== AttendeeRole::Student || $record->absenceNotice()->exists()) {
            return;
        }

        $record->loadMissing(['user', 'lesson.classGroup']);
        $guardians = $record->user->guardians()->orderBy('name')->get();

        if ($guardians->isEmpty()) {
            return;
        }

        $firstName = Str::before($record->user->name, ' ');

        GuardianNotice::create([
            'student_id' => $record->user_id,
            'attendance_record_id' => $record->id,
            'sent_by_id' => $sentBy->id,
            'note' => "{$firstName} was absent from {$record->lesson->classGroup->name} on {$record->lesson->localStart()->format('D j M')}.",
        ]);

        // Emails can't be recalled, so they only go once the register has been saved.
        DB::afterCommit(fn () => $guardians
            ->filter(fn (User $guardian): bool => filled($guardian->email))
            ->each(fn (User $guardian) => Mail::to($guardian->email)->send(new AbsenceMail($record, $guardian))));
    }
}
