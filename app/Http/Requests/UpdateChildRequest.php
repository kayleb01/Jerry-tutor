<?php

namespace App\Http\Requests;

use App\Concerns\AccountValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChildRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Route middleware (`role:guardian`) and the scoped `{child}` binding already restrict who can reach this.
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
        /** @var User $child */
        $child = $this->route('child');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => $this->usernameRules(ignore: $child),
            'email' => $this->emailRules(required: false, ignore: $child),
            'year_group' => $this->yearGroupRules(),
        ];
    }
}
