<?php

namespace App\Http\Requests;

use App\Concerns\AccountValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreChildRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Route middleware (`role:guardian`) already restricts who can reach this.
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
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(required: false),
            'password' => $this->passwordRules(),
            'year_group' => $this->yearGroupRules(),
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'relationship' => ['nullable', 'string', 'max:50'],
        ];
    }
}
