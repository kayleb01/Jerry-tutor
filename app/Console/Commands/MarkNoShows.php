<?php

namespace App\Console\Commands;

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:mark-no-shows')]
#[Description('Mark anyone still unrecorded 15 minutes after a lesson ends as absent, and close the lesson')]
class MarkNoShows extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecordAttendance $recordAttendance): int
    {
        $lessons = Lesson::query()
            ->whereIn('status', [LessonStatus::Scheduled, LessonStatus::InProgress])
            ->where('ends_at', '<=', CarbonImmutable::now()->subMinutes(15))
            ->with(['tutor', 'classGroup', 'attendanceRecords'])
            ->get();

        $marked = 0;

        foreach ($lessons as $lesson) {
            $recorded = $lesson->attendanceRecords->pluck('user_id')->all();

            $people = $lesson->classGroup->activeStudents()->get()->prepend($lesson->tutor);

            $people
                ->reject(fn (User $person): bool => in_array($person->id, $recorded, true))
                ->each(function (User $person) use ($lesson, $recordAttendance, &$marked): void {
                    $recordAttendance->handle($lesson, $person, AttendanceStatus::Absent, null);
                    $marked++;
                });

            $lesson->update(['status' => LessonStatus::Completed]);
        }

        $this->info("Closed {$lessons->count()} lessons and marked {$marked} absences.");

        return self::SUCCESS;
    }
}
