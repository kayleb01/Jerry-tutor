<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LessonReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('submitReport', $this->route('lesson'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'went_well' => ['nullable', 'string', 'max:2000'],
            'issues' => ['nullable', 'string', 'max:2000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('rating') && ! $this->filled('went_well') && ! $this->filled('issues') && ! $this->filled('recommendations')) {
                    $validator->errors()->add('went_well', 'Add a rating or write at least one comment.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['rating.between' => 'Choose a rating from 1 to 5.'];
    }
}
