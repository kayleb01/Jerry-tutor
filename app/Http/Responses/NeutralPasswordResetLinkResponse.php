<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Answer an unknown or recently-emailed address exactly like a successful
 * request, so neither "no such user" nor the per-user throttle reveals who has
 * an account. The broker still refuses to send repeat emails while throttled.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        if (in_array($this->status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        throw ValidationException::withMessages([Fortify::email() => [__($this->status)]]);
    }
}
