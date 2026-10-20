<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClassGroupRequest;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LessonScheduler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ClassGroupController extends Controller
{
    public function index(): Response
    {
        $classes = ClassGroup::query()
            ->with(['slots' => fn (HasMany $query) => $query->with(['subject', 'tutor'])->orderBy('weekday')->orderBy('start_time'), 'lessons' => fn (HasMany $query) => $query->where('starts_at', '>', now())->orderBy('starts_at')->limit(1)])
            ->withCount('activeStudents')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'slots' => $group->slots->map(fn (ClassSlot $slot): string => $this->slotSummary($slot))->all(),
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

    public function store(ClassGroupRequest $request): RedirectResponse
    {
        $group = ClassGroup::create($request->validated());

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
                'yearGroup' => $classGroup->year_group?->value,
                'isActive' => $classGroup->is_active,
                'slots' => $classGroup->slots()
                    ->with(['subject', 'tutor'])
                    ->orderBy('weekday')
                    ->orderBy('start_time')
                    ->get()
                    ->map(fn (ClassSlot $slot): string => $this->slotSummary($slot))
                    ->all(),
            ],
            'students' => $classGroup->activeStudents()
                ->with('studentProfile')
                ->orderBy('name')
                ->get()
                ->map(fn (User $student): array => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'username' => $student->username,
                    'yearGroup' => $student->studentProfile?->year_group->label(),
                    'enrolledOn' => Carbon::parse($student->pivot->enrolled_at)->format('j M Y'),
                ]),
            'lessons' => $classGroup->lessons()
                ->withCount('attendanceRecords')
                ->where('starts_at', '<=', now())
                ->orderByDesc('starts_at')
                ->limit(4)
                ->get()
                ->reverse()
                ->merge($classGroup->lessons()->withCount('attendanceRecords')->where('starts_at', '>', now())->orderBy('starts_at')->limit(4)->get())
                ->map(fn (Lesson $lesson): array => [
                    'id' => $lesson->id,
                    'when' => $lesson->localStart()->format('D j M, H:i'),
                    'status' => $lesson->status->value,
                    'recorded' => $lesson->attendance_records_count,
                ])
                ->values(),
            'candidates' => User::query()
                ->where('role', Role::Student)
                ->whereDoesntHave('enrolledClassGroups', fn (Builder $query) => $query
                    ->whereKey($classGroup->id)
                    ->whereNull('class_group_student.left_at'))
                ->with('studentProfile')
                ->orderBy('name')
                ->get()
                ->map(fn (User $student): array => [
                    'value' => $student->id,
                    'label' => $student->name.($student->studentProfile ? " ({$student->studentProfile->year_group->label()})" : ''),
                ]),
        ]);
    }

    public function update(ClassGroupRequest $request, ClassGroup $classGroup, LessonScheduler $scheduler): RedirectResponse
    {
        $classGroup->fill($request->validated());
        $activeChanged = $classGroup->isDirty('is_active');
        $classGroup->save();

        if ($activeChanged) {
            $classGroup->slots->each(fn (ClassSlot $slot) => $scheduler->rescheduleFuture($slot));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$classGroup->name} has been saved."]);

        return to_route('admin.classes.edit', $classGroup);
    }

    /**
     * @return array{yearGroups: array<int, array{value: int, label: string}>}
     */
    private function formOptions(): array
    {
        return [
            'yearGroups' => YearGroup::options(),
        ];
    }

    /**
     * e.g. "Maths · Mon 09:00–11:00 · Ada Obi".
     */
    private function slotSummary(ClassSlot $slot): string
    {
        return "{$slot->subject->name} · {$slot->timeLabel()} · ".($slot->tutor?->name ?? 'No tutor');
    }
}
