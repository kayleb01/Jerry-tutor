<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubjectRequest;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/subjects/index', [
            'subjects' => Subject::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Subject $subject): array => [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'isActive' => $subject->is_active,
                ]),
        ]);
    }

    public function store(SubjectRequest $request): RedirectResponse
    {
        $subject = Subject::query()->create([
            'name' => $request->validated('name'),
            'sort_order' => ((int) Subject::query()->max('sort_order')) + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$subject->name} added."]);

        return back();
    }

    public function update(SubjectRequest $request, Subject $subject): RedirectResponse
    {
        $subject->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$subject->name} saved."]);

        return back();
    }
}
