<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Bot check: an encrypted render time that a form hands back on submit.
 * Humans take a few seconds to fill a form; scripts post straight away.
 */
class FormTimer
{
    /**
     * An encrypted unix time for the form to carry.
     */
    public static function issue(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Whether at least $minimumSeconds have passed since the token was issued.
     * A missing, garbled or future-dated token fails.
     */
    public static function passed(?string $token, int $minimumSeconds = 3): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $issuedAt = Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        if (! ctype_digit($issuedAt)) {
            return false;
        }

        return now()->getTimestamp() - (int) $issuedAt >= $minimumSeconds;
    }
}
