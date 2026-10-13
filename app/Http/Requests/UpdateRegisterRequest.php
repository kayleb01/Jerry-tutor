<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('takeRegister', $this->route('lesson'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'attendance' => ['required', 'array', 'min:1'],
            'attendance.*.status' => ['required', 'in:present,late,absent,excused'],
            'attendance.*.arrived_at' => ['nullable', 'required_if:attendance.*.status,late', 'date_format:H:i'],
            'attendance.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Every key must be someone on this register: an (ever-)enrolled student, or the tutor if the user may edit the tutor.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Lesson $lesson */
                $lesson = $this->route('lesson');
                $ids = array_map('intval', array_keys((array) $this->input('attendance', [])));
                $canEditTutor = $this->user()->can('recordTutorAttendance', $lesson);

                $allowed = $lesson->classGroup->students()->pluck('users.id')->all();
                if ($canEditTutor) {
                    $allowed[] = $lesson->tutor_id;
                }

                if (array_diff($ids, $allowed) !== []) {
                    $validator->errors()->add('attendance', 'The register can only include this lesson\'s students'.($canEditTutor ? ' and tutor.' : '.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attendance.*.arrived_at.required_if' => 'Enter the time they arrived.',
            'attendance.*.arrived_at.date_format' => 'Use a time like 10:05.',
            'attendance.*.status.in' => 'Choose on time, late, absent or excused.',
        ];
    }
}
