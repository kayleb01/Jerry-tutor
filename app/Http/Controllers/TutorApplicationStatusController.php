<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TutorApplicationStatusController extends Controller
{
    /**
     * Tell a tutor who isn't approved yet where their application stands; anyone without a rejection sees the waiting copy.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasRole(Role::Tutor) || $user->isApprovedTutor()) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/tutor-application-status', [
            'status' => $user->isRejectedTutor() ? ApprovalStatus::Rejected->value : ApprovalStatus::Pending->value,
        ]);
    }
}
