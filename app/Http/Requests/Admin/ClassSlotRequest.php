<?php

namespace App\Http\Requests\Admin;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClassSlotRequest extends FormRequest
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
        $this->merge([
            'tutor_id' => $this->filled('tutor_id') ? $this->input('tutor_id') : null,
            'weekday' => $this->filled('weekday') ? $this->input('weekday') : null,
            'start_time' => $this->filled('start_time') ? $this->input('start_time') : null,
            'location' => $this->filled('location') ? trim((string) $this->input('location')) : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $slot = $this->route('slot');

        return [
            'subject_id' => ['required', 'integer', Rule::exists(Subject::class, 'id')->where(
                fn ($query) => $query->where('is_active', true)->when($slot instanceof ClassSlot, fn ($query) => $query->orWhere('id', $slot->subject_id)),
            )],
            'tutor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(
                fn ($query) => $query->where('role', Role::Tutor->value)->whereNull('deleted_at')->where(
                    fn ($query) => $query->where('approval_status', ApprovalStatus::Approved->value)
                        ->when($slot instanceof ClassSlot && $slot->tutor_id !== null, fn ($query) => $query->orWhere('id', $slot->tutor_id)),
                ),
            )],
            'weekday' => ['nullable', 'required_with:start_time', Rule::enum(Weekday::class)],
            'start_time' => ['nullable', 'required_with:weekday', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $group = $this->route('classGroup');

            if ($validator->errors()->isNotEmpty() || ! $group instanceof ClassGroup || $this->input('weekday') === null) {
                return;
            }

            $slot = $this->route('slot');
            $clash = ClassSlot::overlapping(
                $group,
                Weekday::from((int) $this->input('weekday')),
                $this->input('start_time'),
                (int) $this->input('duration_minutes'),
                $slot instanceof ClassSlot ? $slot->id : null,
            );

            if ($clash !== null) {
                $range = substr($clash->timeLabel(), 4);
                $validator->errors()->add('start_time', "{$group->name} already has {$clash->subject->name} {$range} on {$clash->weekday->label()}");
            }
        }];
    }
}
