<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnrolStudentRequest;
use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ClassEnrolmentController extends Controller
{
    public function store(EnrolStudentRequest $request, ClassGroup $classGroup): RedirectResponse
    {
        $student = User::findOrFail($request->integer('student_id'));
        $classGroup->enrol($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$student->name} has joined {$classGroup->name}."]);

        return to_route('admin.classes.edit', $classGroup);
    }

    public function destroy(ClassGroup $classGroup, User $student): RedirectResponse
    {
        abort_unless($classGroup->activeStudents()->whereKey($student->id)->exists(), 404);

        $classGroup->withdraw($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$student->name} has left {$classGroup->name}."]);

        return to_route('admin.classes.edit', $classGroup);
    }
}
