<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AccountValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
     * A role is fixed at creation, so there is deliberately no `role` rule here.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        $isStudent = $user->hasRole(Role::Student);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules(required: ! $isStudent, ignore: $user),
            'username' => $isStudent ? $this->usernameRules(ignore: $user) : ['exclude'],
            'year_group' => $isStudent ? $this->yearGroupRules() : ['exclude'],
        ];
    }
}
