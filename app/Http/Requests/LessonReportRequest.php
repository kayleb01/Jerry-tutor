<?php

namespace App\Http\Requests;

use App\Enums\RecommendedAction;
use App\Models\Lesson;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LessonReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('submitReport', $this->route('lesson'));
    }

    /**
     * Whether the author is the lesson's tutor (students follow the original, simpler rules).
     */
    public function isTutor(): bool
    {
        return $this->route('lesson')->tutor_id === $this->user()->id;
    }

    /**
     * Only a tutor can save a draft; everyone else always submits.
     */
    public function isDraft(): bool
    {
        return $this->isTutor() && $this->input('action') === 'draft';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', Rule::in(['draft', 'submit'])],
            'rating' => ['nullable', 'integer', 'between:1,5', Rule::requiredIf(fn (): bool => $this->isTutor() && ! $this->isDraft())],
            'went_well' => ['nullable', 'string', 'max:2000'],
            'issues' => ['nullable', 'string', 'max:2000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
            'recommended_actions' => ['nullable', 'array'],
            'recommended_actions.*' => ['string', Rule::enum(RecommendedAction::class)],
            'raises_issue' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Lesson $lesson */
                $lesson = $this->route('lesson');

                if ($this->isDraft()) {
                    abort_if($lesson->lessonReports()->where('author_id', $this->user()->id)->exists(), 422, 'A submitted report cannot go back to a draft.');

                    return;
                }

                if (! $this->isTutor() && ! $this->filled('rating') && ! $this->filled('went_well') && ! $this->filled('issues') && ! $this->filled('recommendations')) {
                    $validator->errors()->add('went_well', 'Add a rating or write at least one comment.');
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
            'rating.between' => 'Choose a rating from 1 to 5.',
            'rating.required' => 'Choose a rating to submit your report.',
        ];
    }
}
