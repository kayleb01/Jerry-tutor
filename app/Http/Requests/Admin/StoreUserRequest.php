<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AccountValidationRules;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
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
        $isStudent = $this->input('role') === Role::Student->value;

        return [
            'role' => ['required', Rule::enum(Role::class)],
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules(required: ! $isStudent),
            'username' => $isStudent ? $this->usernameRules() : ['exclude'],
            'year_group' => $isStudent ? $this->yearGroupRules() : ['exclude'],
            'password' => $this->passwordRules(),
        ];
    }
}
