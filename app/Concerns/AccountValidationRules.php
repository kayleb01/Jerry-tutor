<?php

namespace App\Concerns;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\YearGroup;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation shared by every form that creates or edits an account.
 * Use it from a FormRequest.
 */
trait AccountValidationRules
{
    use PasswordValidationRules;

    /**
     * Lowercase identifiers before validation so `unique` sees the stored form.
     */
    protected function normaliseAccountInput(): void
    {
        $normalised = [];

        foreach (['email', 'username'] as $field) {
            if (is_string($this->input($field))) {
                $value = Str::lower(trim($this->input($field)));
                $normalised[$field] = $value === '' ? null : $value;
            }
        }

        $this->merge($normalised);
    }

    /**
     * @return array<int, mixed>
     */
    protected function usernameRules(?User $ignore = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:30',
            'regex:/^[a-z0-9._-]+$/',
            Rule::unique(User::class, 'username')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function emailRules(bool $required, ?User $ignore = null): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'email',
            'max:255',
            Rule::unique(User::class, 'email')->ignore($ignore),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function yearGroupRules(): array
    {
        return ['required', Rule::enum(YearGroup::class)];
    }
}
