<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RestoreUserController extends Controller
{
    /**
     * Reactivate a deactivated account.
     */
    public function __invoke(User $user): RedirectResponse
    {
        $user->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} has been reactivated."]);

        return to_route('admin.users.edit', $user);
    }
}
