<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClassGroupRequest;
use App\Models\ClassGroup;
use App\Models\User;
use App\Services\LessonScheduler;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClassGroupController extends Controller
{
    public function index(): Response
    {
        $classes = ClassGroup::query()
            ->with(['tutor', 'lessons' => fn (HasMany $query) => $query->where('starts_at', '>', now())->orderBy('starts_at')->limit(1)])
            ->withCount('activeStudents')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'tutor' => $group->tutor?->name,
                'schedule' => $group->scheduleLabel(),
                'students' => $group->active_students_count,
                'nextLesson' => $group->lessons->first()?->starts_at->setTimezone(config('academy.timezone'))->format('D j M, H:i'),
                'isActive' => $group->is_active,
            ]);

        return Inertia::render('admin/classes/index', ['classes' => $classes]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/classes/create', $this->formOptions());
    }

    public function store(ClassGroupRequest $request, LessonScheduler $scheduler): RedirectResponse
    {
        $group = ClassGroup::create($request->validated());
        $scheduler->generateFor($group);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$group->name} has been created."]);

        return to_route('admin.classes.edit', $group);
    }

    public function edit(ClassGroup $classGroup): Response
    {
        return Inertia::render('admin/classes/edit', [
            ...$this->formOptions(),
            'classGroup' => [
                'id' => $classGroup->id,
                'name' => $classGroup->name,
                'tutorId' => $classGroup->tutor_id,
                'yearGroup' => $classGroup->year_group?->value,
                'location' => $classGroup->location,
                'weekday' => $classGroup->weekday?->value,
                'startTime' => $classGroup->start_time,
                'durationMinutes' => $classGroup->duration_minutes,
                'isActive' => $classGroup->is_active,
                'schedule' => $classGroup->scheduleLabel(),
            ],
        ]);
    }

    public function update(ClassGroupRequest $request, ClassGroup $classGroup, LessonScheduler $scheduler): RedirectResponse
    {
        $classGroup->fill($request->validated());
        $scheduleChanged = $classGroup->isDirty(['weekday', 'start_time', 'duration_minutes', 'tutor_id', 'is_active']);
        $classGroup->save();

        if ($scheduleChanged) {
            $scheduler->rescheduleFuture($classGroup);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$classGroup->name} has been saved."]);

        return to_route('admin.classes.edit', $classGroup);
    }

    /**
     * @return array{tutors: array<int, array{value: int, label: string}>, weekdays: array<int, array{value: int, label: string}>, yearGroups: array<int, array{value: int, label: string}>}
     */
    private function formOptions(): array
    {
        return [
            'tutors' => User::query()
                ->where('role', Role::Tutor)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $tutor): array => ['value' => $tutor->id, 'label' => $tutor->name])
                ->all(),
            'weekdays' => Weekday::options(),
            'yearGroups' => YearGroup::options(),
        ];
    }
}
