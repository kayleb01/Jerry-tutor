<?php

namespace App\Http\Requests\Admin;

use App\Support\QuestionInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QuestionRequest extends FormRequest
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
        $this->replace([
            ...QuestionInput::normalise($this->all()),
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...QuestionInput::rules($this->all()),
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_image' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return QuestionInput::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...QuestionInput::attributes(), 'image' => 'diagram'];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return QuestionInput::after($this->all());
    }
}
