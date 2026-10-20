<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTutorIsApproved
{
    /**
     * Keep every tutor the academy hasn't approved (pending, rejected or undecided) on the application status page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->hasRole(Role::Tutor) && ! $user->isApprovedTutor()) {
            return redirect()->route('tutors.application');
        }

        return $next($request);
    }
}
