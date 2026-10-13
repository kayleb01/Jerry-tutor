<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Answer an unknown email exactly like a known one, so the form can't be used
 * to find out who has an account. Throttling still shows its real error.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        if ($this->status === Password::INVALID_USER) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        throw ValidationException::withMessages([Fortify::email() => [__($this->status)]]);
    }
}
