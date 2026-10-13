<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserPasswordController extends Controller
{
    /**
     * Set a new password for any account.
     */
    public function __invoke(UpdatePasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill(['password' => Hash::make($request->validated('password'))])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name}'s password has been changed."]);

        return to_route('admin.users.edit', $user);
    }
}
