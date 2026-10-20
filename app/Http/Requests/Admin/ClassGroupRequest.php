<?php

namespace App\Http\Requests\Admin;

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
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'year_group' => ['nullable', Rule::enum(YearGroup::class)],
            'is_active' => ['boolean'],
        ];
    }
}
