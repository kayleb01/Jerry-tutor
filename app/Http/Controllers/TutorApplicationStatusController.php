<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TutorApplicationStatusController extends Controller
{
    /**
     * Tell a pending or rejected tutor where their application stands.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isPendingTutor() && ! $user->isRejectedTutor()) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/tutor-application-status', [
            'status' => $user->isRejectedTutor() ? ApprovalStatus::Rejected->value : ApprovalStatus::Pending->value,
        ]);
    }
}
