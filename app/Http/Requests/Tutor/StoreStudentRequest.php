<?php

namespace App\Http\Requests\Tutor;

use App\Concerns\AccountValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Whether the tutor teaches this class is checked in the controller through ClassGroupPolicy.
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
            'email' => $this->emailRules(required: false),
            'username' => $this->usernameRules(),
            'year_group' => $this->yearGroupRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
