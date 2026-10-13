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
     * The tutor checks themselves in; the first check-in time is kept.
     */
    public function __invoke(Request $request, Lesson $lesson, RecordAttendance $recordAttendance): RedirectResponse
    {
        Gate::authorize('checkIn', $lesson);

        $alreadyIn = $lesson->attendanceRecords()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('arrived_at')
            ->exists();

        if (! $alreadyIn) {
            $record = $recordAttendance->handle($lesson, $request->user(), AttendanceStatus::Present, $request->user(), CarbonImmutable::now());

            if ($lesson->status === LessonStatus::Scheduled) {
                $lesson->update(['status' => LessonStatus::InProgress]);
            }

            $message = $record->status === AttendanceStatus::Late
                ? "Checked in {$record->minutes_late} min after the start."
                : 'Checked in on time.';
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return to_route('lessons.register.show', $lesson);
    }
}
