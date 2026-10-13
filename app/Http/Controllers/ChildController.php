<?php

namespace App\Http\Controllers;

use App\Actions\CreateStudentAccount;
use App\Enums\YearGroup;
use App\Http\Requests\StoreChildRequest;
use App\Http\Requests\UpdateChildRequest;
use App\Models\User;
use App\Services\AttendanceStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChildController extends Controller
{
    public function index(Request $request, AttendanceStats $stats): Response
    {
        $children = $request->user()->children()
            ->with('studentProfile')
            ->orderBy('name')
            ->get()
            ->map(function (User $child) use ($stats): array {
                $summary = $stats->forUser($child);

                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'username' => $child->username,
                    'email' => $child->email,
                    'yearGroup' => $child->studentProfile?->year_group->label(),
                    'attendance' => $summary['attendance'],
                    'late' => $summary['late'],
                ];
            });

        return Inertia::render('children/index', ['children' => $children]);
    }

    public function create(): Response
    {
        return Inertia::render('children/create', ['yearGroups' => YearGroup::options()]);
    }

    public function store(StoreChildRequest $request, CreateStudentAccount $createStudent): RedirectResponse
    {
        $child = $createStudent->handle(
            $request->safe()->only(['name', 'username', 'email', 'password', 'year_group', 'date_of_birth']),
            $request->user(),
            $request->validated('relationship'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$child->name} has been added."]);

        return to_route('children.index');
    }

    public function edit(User $child): Response
    {
        return Inertia::render('children/edit', [
            'child' => [
                'id' => $child->id,
                'name' => $child->name,
                'username' => $child->username,
                'email' => $child->email,
                'yearGroup' => $child->studentProfile?->year_group->value,
            ],
            'yearGroups' => YearGroup::options(),
        ]);
    }

    public function update(UpdateChildRequest $request, User $child): RedirectResponse
    {
        $child->fill($request->safe()->only(['name', 'email']));
        $child->forceFill(['username' => $request->validated('username')]);

        if ($child->isDirty('email') && $child->email !== null) {
            $child->forceFill(['email_verified_at' => now()]);
        }

        $child->save();
        $child->studentProfile()->update(['year_group' => (int) $request->validated('year_group')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$child->name}'s details have been saved."]);

        return to_route('children.index');
    }
}
