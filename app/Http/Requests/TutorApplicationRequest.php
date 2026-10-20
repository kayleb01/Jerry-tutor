<?php

namespace App\Http\Requests;

use App\Concerns\AccountValidationRules;
use App\Support\FormTimer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TutorApplicationRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * The route is behind the `guest` middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseAccountInput();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules(required: true),
            'phone' => ['required', 'string', 'max:20'],
            'password' => $this->passwordRules(),
            'classes' => ['required', 'array', 'min:1', 'max:50'],
            'classes.*.class_group_id' => ['required', 'integer', Rule::exists('class_groups', 'id')->where('is_active', true)],
            'classes.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('is_active', true)],
            'website' => ['nullable', 'string'],
            'form_started' => ['nullable', 'string'],
        ];
    }

    /**
     * Bot checks (honeypot, minimum fill time) and distinct class and subject pairs.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('website') || ! FormTimer::passed($this->input('form_started'))) {
                    $validator->errors()->add('form', 'Something went wrong, please try again.');
                }
            },
            function (Validator $validator): void {
                $seen = [];

                foreach ((array) $this->input('classes', []) as $index => $pair) {
                    if (! is_array($pair)) {
                        continue;
                    }

                    $key = ($pair['class_group_id'] ?? '').'-'.($pair['subject_id'] ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add("classes.{$index}", 'You already added this class and subject.');
                    }

                    $seen[$key] = true;
                }
            },
        ];
    }

    /**
     * The validated class and subject pairs.
     *
     * @return array<int, array{class_group_id: int, subject_id: int}>
     */
    public function classPairs(): array
    {
        return collect($this->validated('classes'))
            ->map(fn (array $pair): array => [
                'class_group_id' => (int) $pair['class_group_id'],
                'subject_id' => (int) $pair['subject_id'],
            ])
            ->all();
    }
}
