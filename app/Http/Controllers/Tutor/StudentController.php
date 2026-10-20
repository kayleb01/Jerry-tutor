<?php

namespace App\Http\Controllers\Tutor;

use App\Actions\CreateStudentAccount;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\StoreStudentRequest;
use App\Models\ClassGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function create(ClassGroup $classGroup): Response
    {
        Gate::authorize('addStudent', $classGroup);

        return Inertia::render('tutor/students/create', [
            'classGroup' => [
                'id' => $classGroup->id,
                'name' => $classGroup->name,
                'yearGroup' => $classGroup->year_group?->value,
            ],
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function store(StoreStudentRequest $request, ClassGroup $classGroup, CreateStudentAccount $createStudent): RedirectResponse
    {
        $student = DB::transaction(function () use ($request, $classGroup, $createStudent) {
            $student = $createStudent->handle($request->validated());
            $classGroup->enrol($student);

            return $student;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$student->name} added to {$classGroup->name}."]);

        return to_route('tutor.classes.index');
    }
}
