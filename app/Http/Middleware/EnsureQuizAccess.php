<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureQuizAccess
{
    /**
     * Quiz pages are for staff and for students with a paid-up subscription; other students see the paywall.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->hasQuizAccess()) {
            return $next($request);
        }

        abort_unless($user?->hasRole(Role::Student), 403);

        $guardians = $user->guardians()->orderBy('name')->pluck('name')->all();

        return Inertia::render('billing/paywall', [
            'guardians' => $guardians,
            'canPay' => $guardians === [],
        ])->toResponse($request);
    }
}
