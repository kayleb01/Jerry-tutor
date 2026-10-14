<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewLatenessReportRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'follow_up_notes' => ['nullable', 'string', 'max:2000'],
            'reviewed' => ['required', 'boolean'],
            'next' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
