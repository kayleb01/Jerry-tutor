<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class ChildPasswordController extends Controller
{
    /**
     * Set a new password for one of the guardian's children.
     */
    public function __invoke(UpdatePasswordRequest $request, User $child): RedirectResponse
    {
        $child->forceFill(['password' => Hash::make($request->validated('password'))])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$child->name}'s password has been changed."]);

        return to_route('children.edit', $child);
    }
}
