<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Validator;

class StudentCheckInRequest extends FormRequest
{
    /** Wrong codes allowed per student per lesson before a lockout. */
    public const MAX_TRIES = 5;

    /** How long a lockout lasts, in seconds. */
    public const LOCKOUT_SECONDS = 600;

    public function authorize(): bool
    {
        return $this->user()->can('studentCheckIn', $this->route('lesson'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:4'],
        ];
    }

    /**
     * Checks the code against the lesson's, counting wrong guesses so the code can't be found by trying them all.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Lesson $lesson */
                $lesson = $this->route('lesson');
                $key = "student-check-in:{$this->user()->id}:{$lesson->id}";

                if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
                    $validator->errors()->add('code', 'Too many tries. Ask your tutor.');

                    return;
                }

                if ($validator->errors()->has('code')) {
                    return;
                }

                if (! hash_equals($lesson->checkInCode(), (string) $this->input('code'))) {
                    RateLimiter::hit($key, self::LOCKOUT_SECONDS);
                    $validator->errors()->add('code', "That code isn't right. Check the number on your tutor's screen.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Type the 4-digit code on your tutor\'s screen.',
            'code.digits' => 'The code is 4 numbers.',
        ];
    }
}
