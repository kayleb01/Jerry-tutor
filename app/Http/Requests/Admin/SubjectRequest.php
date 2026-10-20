<?php

namespace App\Http\Requests\Admin;

use App\Models\Subject;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubjectRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * A rename needs a name; the activate/deactivate toggle sends only `is_active`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $subject = $this->route('subject');

        return [
            'name' => [$subject instanceof Subject ? 'filled' : 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Names are unique ignoring case (the database comparison is case-sensitive).
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('name')) {
                return;
            }

            $subject = $this->route('subject');

            $taken = Subject::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $this->input('name'))])
                ->when($subject instanceof Subject, fn ($query) => $query->whereKeyNot($subject->getKey()))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'There is already a subject with that name.');
            }
        }];
    }
}
