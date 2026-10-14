<?php

namespace App\Support;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\QuestionType;
use App\Enums\YearGroup;
use App\Models\Topic;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The single definition of a valid question, shared by the admin form and the CSV import.
 */
final class QuestionInput
{
    /**
     * Trim the raw input and keep only the answer fields the question's type uses.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $type = QuestionType::tryFrom((string) ($input['type'] ?? ''));

        $data = [
            'topic_id' => $input['topic_id'] ?? null,
            'difficulty' => $input['difficulty'] ?? null,
            'year_group' => self::blankToNull($input['year_group'] ?? null),
            'type' => $input['type'] ?? null,
            'stem' => self::trimmed($input['stem'] ?? null),
            'explanation' => self::blankToNull(self::trimmed($input['explanation'] ?? null)),
            'marks' => self::blankToNull($input['marks'] ?? null) ?? 1,
            'exam_tracks' => array_values(array_filter((array) ($input['exam_tracks'] ?? []), fn (mixed $track): bool => filled($track))),
        ];

        if ($type?->hasOptions()) {
            $options = array_map(self::trimmed(...), array_values((array) ($input['options'] ?? [])));

            while ($options !== [] && in_array(end($options), ['', null], true)) {
                array_pop($options);
            }

            $data['options'] = $options;
            $data['correct'] = array_values(array_unique(array_map('intval', array_filter((array) ($input['correct'] ?? []), 'is_numeric'))));
        }

        if ($type === QuestionType::Numeric) {
            $data['numeric_answer'] = self::blankToNull($input['numeric_answer'] ?? null);
            $data['numeric_tolerance'] = self::blankToNull($input['numeric_tolerance'] ?? null) ?? 0;
        }

        if ($type === QuestionType::ShortText) {
            $answers = $input['accepted_answers'] ?? [];
            $answers = is_string($answers) ? preg_split('/\R/', $answers) : (array) $answers;
            $data['accepted_answers'] = array_values(array_filter(array_map(self::trimmed(...), $answers), fn (mixed $answer): bool => filled($answer)));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  normalised input
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $data): array
    {
        $lastOption = max(0, count($data['options'] ?? []) - 1);

        return [
            'topic_id' => ['required', 'integer', Rule::exists('topics', 'id')],
            'difficulty' => ['required', Rule::enum(Difficulty::class)],
            'year_group' => ['nullable', Rule::enum(YearGroup::class)],
            'type' => ['required', Rule::enum(QuestionType::class)],
            'stem' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'marks' => ['required', 'integer', 'min:1', 'max:5'],
            'exam_tracks' => ['array'],
            'exam_tracks.*' => ['distinct', Rule::enum(ExamTrack::class)],
            'options' => ['sometimes', 'required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:500'],
            'correct' => ['sometimes', 'required', 'array'],
            'correct.*' => ['integer', 'min:0', "max:{$lastOption}"],
            'numeric_answer' => ['sometimes', 'required', 'numeric', 'between:-99999999,99999999', 'decimal:0,4'],
            'numeric_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:99999999', 'decimal:0,4'],
            'accepted_answers' => ['sometimes', 'required', 'array', 'max:10'],
            'accepted_answers.*' => ['string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'topic_id.required' => 'Choose a topic.',
            'topic_id.exists' => 'Choose a topic from the list.',
            'options.required' => 'Add at least two options.',
            'options.min' => 'Add at least two options.',
            'options.max' => 'Use at most six options.',
            'options.*.required' => 'Fill in this option or remove it.',
            'correct.required' => 'Mark the correct answer.',
            'correct.*.min' => 'The correct answer must be one of the options.',
            'correct.*.max' => 'The correct answer must be one of the options.',
            'numeric_answer.required' => 'Enter the correct number.',
            'accepted_answers.required' => 'Add at least one accepted answer.',
            'accepted_answers.max' => 'Use at most ten accepted answers.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'topic_id' => 'topic',
            'year_group' => 'year group',
            'stem' => 'question',
            'options.*' => 'option',
            'numeric_answer' => 'answer',
            'numeric_tolerance' => 'tolerance',
            'accepted_answers.*' => 'accepted answer',
            'exam_tracks.*' => 'exam track',
        ];
    }

    /**
     * Cross-field checks that only make sense once the basic rules pass.
     *
     * @param  array<string, mixed>  $data  normalised input
     * @return array<int, Closure(Validator): void>
     */
    public static function after(array $data): array
    {
        return [function (Validator $validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = QuestionType::from($data['type']);
            $correct = count($data['correct'] ?? []);

            if ($type === QuestionType::MultipleChoice && $correct > 1) {
                $validator->errors()->add('correct', 'A multiple-choice question has exactly one correct answer.');
            }

            if ($type === QuestionType::MultiSelect && $correct === count($data['options'])) {
                $validator->errors()->add('correct', 'At least one option must be wrong.');
            }

            $yearGroup = $data['year_group'] === null ? null : YearGroup::from((int) $data['year_group']);
            $keyStage = Topic::find($data['topic_id'])->key_stage;

            if ($yearGroup && $yearGroup->keyStage() !== $keyStage) {
                $validator->errors()->add('year_group', "{$yearGroup->label()} isn't in {$keyStage->label()}. Choose a {$keyStage->label()} year or leave it blank.");
            }
        }];
    }

    private static function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private static function blankToNull(mixed $value): mixed
    {
        return blank($value) ? null : $value;
    }
}
