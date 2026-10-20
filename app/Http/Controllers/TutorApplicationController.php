<?php

namespace App\Http\Controllers;

use App\Actions\CreateTutorApplication;
use App\Http\Requests\TutorApplicationRequest;
use App\Models\ClassGroup;
use App\Models\Subject;
use App\Support\FormTimer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TutorApplicationController extends Controller
{
    /**
     * The public tutor sign-up form.
     */
    public function create(): Response
    {
        return Inertia::render('auth/tutor-join', [
            'classOptions' => ClassGroup::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])
                ->all(),
            'subjectOptions' => Subject::options(),
            'formStarted' => FormTimer::issue(),
        ]);
    }

    /**
     * Create the pending tutor, send the verification email and sign them in.
     */
    public function store(TutorApplicationRequest $request, CreateTutorApplication $createTutorApplication): RedirectResponse
    {
        $user = $createTutorApplication->handle(
            $request->safe()->only(['name', 'email', 'phone', 'password']),
            $request->classPairs(),
        );

        event(new Registered($user));
        Auth::login($user);

        return to_route('verification.notice');
    }
}
