<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\RecommendedAction;
use App\Http\Requests\LessonReportRequest;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Services\TutorReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LessonReportController extends Controller
{
    public function __construct(private readonly TutorReports $tutorReports) {}

    /**
     * The sidebar link: the tutor's default class to report on, or an all-caught-up page.
     */
    public function next(Request $request): RedirectResponse|Response
    {
        $lesson = $this->tutorReports->defaultLesson($request->user());

        if ($lesson === null) {
            return Inertia::render('lessons/report-caught-up');
        }

        return to_route('lessons.report.edit', $lesson);
    }

    public function edit(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('submitReport', $lesson);

        $timezone = config('academy.timezone');
        $role = $this->roleFor($request, $lesson);
        $isTutor = $role === AttendeeRole::Tutor;
        $report = $lesson->reportsIncludingDrafts()->where('author_id', $request->user()->id)->first();

        return Inertia::render('lessons/report', [
            'lesson' => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'date' => $lesson->localStart()->format('l j F'),
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
            ],
            'authorRole' => $role->value,
            'report' => $report === null ? null : [
                'rating' => $report->rating,
                'wentWell' => $report->went_well,
                'issues' => $report->issues,
                'recommendations' => $report->recommendations,
                'recommendedActions' => ($report->recommended_actions ?? collect())->map(fn (RecommendedAction $action): string => $action->value)->values()->all(),
                'raisesIssue' => $report->raises_issue,
                'isDraft' => $report->isDraft(),
                'submittedAt' => $report->submitted_at?->setTimezone($timezone)->format('D j M, H:i'),
            ],
            'closesAt' => $lesson->ends_at->addHours(48)->setTimezone($timezone)->format('D j M, H:i'),
            'classOptions' => $isTutor ? $this->classOptions($request, $lesson) : null,
            'recommendationOptions' => RecommendedAction::options(),
            'panel' => $isTutor ? $this->panel($lesson) : null,
        ]);
    }

    public function update(LessonReportRequest $request, Lesson $lesson): RedirectResponse
    {
        $isDraft = $request->isDraft();

        LessonReport::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'author_id' => $request->user()->id],
            [
                'author_role' => $this->roleFor($request, $lesson),
                'rating' => $request->validated('rating'),
                'went_well' => $request->validated('went_well'),
                'issues' => $request->validated('issues'),
                'recommendations' => $request->validated('recommendations'),
                'recommended_actions' => array_values(array_unique($request->validated('recommended_actions') ?? [])),
                'raises_issue' => $request->isTutor() && $request->boolean('raises_issue'),
                'submitted_at' => $isDraft ? null : CarbonImmutable::now(),
            ],
        );

        if ($isDraft) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved. Only you can see it.']);

            return to_route('lessons.report.edit', $lesson);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks — your report has been saved.']);

        return to_route('dashboard');
    }

    /**
     * The classes the tutor can switch to: those still needing a report plus the one being viewed.
     *
     * @return array<int, array{id: int, label: string}>
     */
    private function classOptions(Request $request, Lesson $current): array
    {
        $lessons = $this->tutorReports->needingReport($request->user())->with('classGroup')->get();

        if (! $lessons->contains('id', $current->id)) {
            $lessons->push($current);
        }

        return $lessons
            ->sortByDesc('starts_at')
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'label' => $lesson->classGroup->name.' · '.$lesson->localStart()->format('D j M, H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{date: string, time: string, tutor: string|null, attendance: string}
     */
    private function panel(Lesson $lesson): array
    {
        $students = $lesson->attendanceRecords()
            ->where('attendee_role', AttendeeRole::Student)
            ->whereNotNull('recorded_by_id')
            ->pluck('status');
        $present = $students->filter(fn (AttendanceStatus $status): bool => in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late], true))->count();

        return [
            'date' => $lesson->localStart()->format('D j M Y'),
            'time' => $lesson->localStart()->format('H:i').' – '.$lesson->localEnd()->format('H:i'),
            'tutor' => $lesson->tutor?->name,
            'attendance' => $students->isEmpty() ? 'Register not taken' : "{$present} of {$students->count()} present",
        ];
    }

    private function roleFor(Request $request, Lesson $lesson): AttendeeRole
    {
        return $lesson->tutor_id === $request->user()->id ? AttendeeRole::Tutor : AttendeeRole::Student;
    }
}
