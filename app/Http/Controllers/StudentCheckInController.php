<?php

namespace App\Http\Controllers;

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Http\Requests\StudentCheckInRequest;
use App\Models\Lesson;
use App\Services\StudentCheckIn;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class StudentCheckInController extends Controller
{
    public function show(Request $request, StudentCheckIn $checkIn): Response
    {
        $card = $checkIn->card($request->user());

        return Inertia::render('check-in/index', [
            'card' => $card,
            'next' => $card === null ? $checkIn->nextLessonLabel($request->user()) : null,
            'timezone' => config('academy.timezone'),
        ]);
    }

    /**
     * The student checks themselves in with the tutor's code. Like the tutor's check-in, an existing record is
     * never overwritten, but the lesson's status is left alone: only the tutor starts a lesson.
     */
    public function store(StudentCheckInRequest $request, Lesson $lesson, RecordAttendance $recordAttendance): RedirectResponse
    {
        $record = $recordAttendance->handle(
            $lesson,
            $request->user(),
            AttendanceStatus::Present,
            $request->user(),
            CarbonImmutable::now(),
            onlyIfUnrecorded: true,
        );
        RateLimiter::clear(StudentCheckInRequest::throttleKey($request->user(), $lesson));

        if ($record->wasRecentlyCreated) {
            $time = $record->arrived_at->setTimezone(config('academy.timezone'))->format('H:i');
            $when = $record->status === AttendanceStatus::Late ? "{$record->minutes_late} min late" : 'On time';
            Inertia::flash('toast', ['type' => 'success', 'message' => "Checked in at {$time} · {$when}"]);
        }

        return to_route('check-in.show');
    }
}
