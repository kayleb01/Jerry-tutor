<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassGroupRequest extends FormRequest
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
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'weekday' => $this->filled('weekday') ? $this->input('weekday') : null,
            'start_time' => $this->filled('start_time') ? $this->input('start_time') : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tutor_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Tutor->value)->whereNull('deleted_at'),
            ],
            'year_group' => ['nullable', Rule::enum(YearGroup::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'weekday' => ['nullable', 'required_with:start_time', Rule::enum(Weekday::class)],
            'start_time' => ['nullable', 'required_with:weekday', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tutor_id.exists' => 'Choose an active tutor.',
            'weekday.required_with' => 'Choose a day for the start time.',
            'start_time.required_with' => 'Choose a start time for the day.',
        ];
    }
}
