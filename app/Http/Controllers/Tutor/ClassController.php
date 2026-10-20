<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassController extends Controller
{
    /**
     * The tutor's classes, with their own slots and the students currently enrolled. Adding a student
     * mirrors ClassGroupPolicy::addStudent using the slots already loaded for this tutor.
     */
    public function index(Request $request): Response
    {
        $tutor = $request->user();

        $classes = ClassGroup::query()
            ->taughtBy($tutor)
            ->with([
                'slots' => fn ($slots) => $slots->where('tutor_id', $tutor->id)->with('subject'),
                'activeStudents' => fn ($students) => $students->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('tutor/classes/index', [
            'classes' => $classes->map(fn (ClassGroup $class): array => [
                'id' => $class->id,
                'name' => $class->name,
                'yearGroup' => $class->year_group?->label(),
                'isActive' => $class->is_active,
                'canAddStudent' => $class->is_active && $class->slots->isNotEmpty(),
                'slots' => $class->slots
                    ->sortBy(fn (ClassSlot $slot): string => ($slot->weekday?->value ?? 9).'|'.$slot->start_time)
                    ->values()
                    ->map(fn (ClassSlot $slot): array => [
                        'id' => $slot->id,
                        'label' => $slot->timeLabel().' · '.$slot->subject->name,
                    ])->all(),
                'students' => $class->activeStudents->map(fn (User $student): array => [
                    'id' => $student->id,
                    'name' => $student->name,
                ])->all(),
            ])->all(),
        ]);
    }
}
