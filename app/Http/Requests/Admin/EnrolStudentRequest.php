<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\ClassGroup;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EnrolStudentRequest extends FormRequest
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
            'student_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Student->value)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('student_id')) {
                    return;
                }

                /** @var ClassGroup $group */
                $group = $this->route('classGroup');

                if ($group->activeStudents()->whereKey($this->integer('student_id'))->exists()) {
                    $validator->errors()->add('student_id', 'That student is already in this class.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['student_id.exists' => 'Choose an active student.'];
    }
}
