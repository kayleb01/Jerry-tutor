<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Validator;

class StudentCheckInRequest extends FormRequest
{
    /** Tries allowed per student per lesson before they are refused. */
    public const MAX_TRIES = 5;

    /** The try count resets this many seconds after the first try. */
    public const DECAY_SECONDS = 600;

    /**
     * The rate-limiter key counting one student's tries for one lesson.
     */
    public static function throttleKey(User $student, Lesson $lesson): string
    {
        return "student-check-in:{$student->id}:{$lesson->id}";
    }

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
                // Count the try before checking it, so parallel guesses can't all slip under the limit.
                $tries = RateLimiter::hit(self::throttleKey($this->user(), $lesson), self::DECAY_SECONDS);

                if ($tries > self::MAX_TRIES) {
                    $validator->errors()->add('code', 'Too many tries. Ask your tutor.');

                    return;
                }

                if ($validator->errors()->has('code')) {
                    return;
                }

                if (! hash_equals($lesson->checkInCode(), (string) $this->input('code'))) {
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
