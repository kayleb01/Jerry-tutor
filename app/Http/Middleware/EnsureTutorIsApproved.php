<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTutorIsApproved
{
    /**
     * Keep tutors who are pending or rejected on the application status page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ($user->isPendingTutor() || $user->isRejectedTutor())) {
            return redirect()->route('tutors.application');
        }

        return $next($request);
    }
}
