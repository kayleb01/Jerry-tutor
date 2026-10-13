<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendeeRole;
use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\LessonReport;
use App\Support\AcademyDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LessonReportController extends Controller
{
    /**
     * End-of-class reports from tutors and students, newest lesson first.
     */
    public function index(Request $request): Response
    {
        $classId = filter_var($request->query('class'), FILTER_VALIDATE_INT) ?: null;
        $role = AttendeeRole::tryFrom((string) $request->query('role'));
        $from = AcademyDate::fromQuery($request->query('from'));
        $to = AcademyDate::fromQuery($request->query('to'));

        $reports = LessonReport::query()
            ->select('lesson_reports.*')
            ->join('lessons', 'lessons.id', '=', 'lesson_reports.lesson_id')
            ->with(['lesson.classGroup', 'author'])
            ->when($classId, fn (Builder $query) => $query->where('lessons.class_group_id', $classId))
            ->when($role, fn (Builder $query) => $query->where('lesson_reports.author_role', $role))
            ->when($from, fn (Builder $query) => $query->where('lessons.starts_at', '>=', $from->startOfDay()->utc()))
            ->when($to, fn (Builder $query) => $query->where('lessons.starts_at', '<=', $to->endOfDay()->utc()))
            ->orderByDesc('lessons.starts_at')
            ->orderByDesc('lesson_reports.id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LessonReport $report): array => [
                'id' => $report->id,
                'lessonDate' => $report->lesson->localStart()->format('D j M Y'),
                'className' => $report->lesson->classGroup->name,
                'author' => $report->author->name,
                'authorRole' => $report->author_role->value,
                'rating' => $report->rating,
                'wentWell' => $report->went_well,
                'issues' => $report->issues,
                'recommendations' => $report->recommendations,
            ]);

        return Inertia::render('admin/lesson-reports/index', [
            'reports' => $reports,
            'filters' => [
                'class' => $classId,
                'role' => $role?->value,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'classes' => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])
                ->all(),
        ]);
    }
}
