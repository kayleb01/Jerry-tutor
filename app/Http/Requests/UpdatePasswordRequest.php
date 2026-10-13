<?php

namespace App\Http\Requests;

use App\Concerns\AccountValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    use AccountValidationRules;

    /**
     * Route middleware and bindings already scope who can reach this.
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
        return ['password' => $this->passwordRules()];
    }
}
