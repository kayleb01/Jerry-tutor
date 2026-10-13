<?php

namespace App\Http\Controllers;

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TutorCheckInController extends Controller
{
    /**
     * The tutor checks themselves in. An existing record (an earlier check-in, an admin's entry or a no-show) is never overwritten.
     */
    public function __invoke(Request $request, Lesson $lesson, RecordAttendance $recordAttendance): RedirectResponse
    {
        Gate::authorize('checkIn', $lesson);

        $record = $recordAttendance->handle(
            $lesson,
            $request->user(),
            AttendanceStatus::Present,
            $request->user(),
            CarbonImmutable::now(),
            onlyIfUnrecorded: true,
        );

        if (! $record->wasRecentlyCreated) {
            if ($record->arrived_at === null) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'Your attendance for this lesson is already recorded as '.strtolower($record->status->label()).'. Ask an admin if it needs changing.']);
            }

            return to_route('lessons.register.show', $lesson);
        }

        if ($lesson->status === LessonStatus::Scheduled) {
            $lesson->update(['status' => LessonStatus::InProgress]);
        }

        $message = $record->status === AttendanceStatus::Late
            ? "Arrived {$record->minutes_late} min after the start."
            : 'Checked in on time.';
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('lessons.register.show', $lesson);
    }
}
