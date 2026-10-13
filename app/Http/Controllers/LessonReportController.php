<?php

namespace App\Http\Controllers;

use App\Enums\AttendeeRole;
use App\Http\Requests\LessonReportRequest;
use App\Models\Lesson;
use App\Models\LessonReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonReportController extends Controller
{
    public function edit(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('submitReport', $lesson);

        $timezone = config('academy.timezone');
        $report = $lesson->lessonReports()->where('author_id', $request->user()->id)->first();

        return Inertia::render('lessons/report', [
            'lesson' => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'date' => $lesson->localStart()->format('l j F'),
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
            ],
            'authorRole' => $this->roleFor($request, $lesson)->value,
            'report' => $report === null ? null : [
                'rating' => $report->rating,
                'wentWell' => $report->went_well,
                'issues' => $report->issues,
                'recommendations' => $report->recommendations,
                'submittedAt' => $report->submitted_at->setTimezone($timezone)->format('D j M, H:i'),
            ],
            'closesAt' => $lesson->ends_at->addHours(48)->setTimezone($timezone)->format('D j M, H:i'),
        ]);
    }

    public function update(LessonReportRequest $request, Lesson $lesson): RedirectResponse
    {
        LessonReport::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'author_id' => $request->user()->id],
            [
                'author_role' => $this->roleFor($request, $lesson),
                'rating' => $request->validated('rating'),
                'went_well' => $request->validated('went_well'),
                'issues' => $request->validated('issues'),
                'recommendations' => $request->validated('recommendations'),
                'submitted_at' => CarbonImmutable::now(),
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks — your report has been saved.']);

        return to_route('dashboard');
    }

    private function roleFor(Request $request, Lesson $lesson): AttendeeRole
    {
        return $lesson->tutor_id === $request->user()->id ? AttendeeRole::Tutor : AttendeeRole::Student;
    }
}
