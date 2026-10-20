<?php

namespace App\Http\Requests\Admin;

use App\Enums\Weekday;
use App\Models\ClassSlot;
use App\Models\TutorClassRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One decision per listed slot of each requested class and subject, and one per request with no slot.
 */
class ApproveTutorRequest extends FormRequest
{
    public const array ACTIONS = ['assign', 'handover', 'leave', 'create', 'skip'];

    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An approved tutor, an unverified application or a non-tutor has nothing to approve.
     */
    protected function prepareForValidation(): void
    {
        abort_unless($this->applicant()->hasReviewableApplication(), 404);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decisions' => ['present', 'array'],
            'decisions.*' => ['array'],
            'decisions.*.request_id' => ['required', 'integer'],
            'decisions.*.slot_id' => ['nullable', 'integer'],
            'decisions.*.action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'decisions.*.weekday' => ['nullable', 'required_if:decisions.*.action,create', Rule::enum(Weekday::class)],
            'decisions.*.start_time' => ['nullable', 'required_if:decisions.*.action,create', 'date_format:H:i'],
            'decisions.*.duration_minutes' => ['nullable', 'required_if:decisions.*.action,create', 'integer', 'min:15', 'max:240'],
            'decisions.*.location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decisions.*.weekday.required_if' => 'Choose a day for the new slot.',
            'decisions.*.start_time.required_if' => 'Choose a start time for the new slot.',
            'decisions.*.duration_minutes.required_if' => 'Give the new slot a length.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $expected = $this->expectedDecisions();
            $decided = [];
            /** @var array<int, array<int, ClassSlot>> $planned new slots in this approval, by class */
            $planned = [];

            foreach ($this->input('decisions') as $index => $decision) {
                $request = $expected['requests']->get((int) $decision['request_id']);

                if ($request === null) {
                    $validator->errors()->add("decisions.{$index}.request_id", 'That class and subject is not part of this application.');

                    continue;
                }

                $key = $request->id.':'.($decision['slot_id'] ?? '');

                if (! array_key_exists($key, $expected['slots'])) {
                    $validator->errors()->add("decisions.{$index}.slot_id", "That slot isn't listed for {$request->label()}.");

                    continue;
                }

                if (isset($decided[$key])) {
                    $validator->errors()->add("decisions.{$index}.slot_id", 'That slot has already been decided.');

                    continue;
                }

                $decided[$key] = true;
                $slot = $expected['slots'][$key];
                $allowed = match (true) {
                    $slot === null => ['create', 'skip'],
                    $slot->tutor_id === null => ['assign', 'skip'],
                    default => ['handover', 'leave'],
                };

                if (! in_array($decision['action'], $allowed, true)) {
                    $validator->errors()->add("decisions.{$index}.action", 'Choose one of the options listed for this slot.');

                    continue;
                }

                if ($decision['action'] === 'create') {
                    $clash = $this->clashFor($request, $decision, $planned[$request->class_group_id] ?? []);

                    if ($clash !== null) {
                        $validator->errors()->add("decisions.{$index}.start_time", $clash->clashMessage());

                        continue;
                    }

                    $planned[$request->class_group_id][] = $this->plannedSlot($request, $decision);
                }
            }

            if ($validator->errors()->isEmpty() && count($decided) < count($expected['slots'])) {
                $validator->errors()->add('decisions', 'Decide every class and subject before approving.');
            }
        }];
    }

    public function applicant(): User
    {
        /** @var User $applicant */
        $applicant = $this->route('tutor');

        return $applicant;
    }

    /**
     * The applicant's requests by id, and every decision owed, keyed "{request}:{slot}" ("{request}:" when there is no slot).
     *
     * @return array{requests: Collection<int, TutorClassRequest>, slots: array<string, ClassSlot|null>}
     */
    private function expectedDecisions(): array
    {
        $requests = $this->applicant()->requestedClasses()->with(['classGroup', 'subject'])->get()->keyBy('id');
        $slots = ClassSlot::query()
            ->whereIn('class_group_id', $requests->pluck('class_group_id'))
            ->whereIn('subject_id', $requests->pluck('subject_id'))
            ->get();
        $expected = [];

        foreach ($requests as $request) {
            $matching = $slots->where('class_group_id', $request->class_group_id)->where('subject_id', $request->subject_id);

            if ($matching->isEmpty()) {
                $expected["{$request->id}:"] = null;
            }

            foreach ($matching as $slot) {
                $expected["{$request->id}:{$slot->id}"] = $slot;
            }
        }

        return ['requests' => $requests, 'slots' => $expected];
    }

    /**
     * An existing slot of the class, or one created earlier in this approval, that the new slot would overlap.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<int, ClassSlot>  $planned
     */
    private function clashFor(TutorClassRequest $request, array $decision, array $planned): ?ClassSlot
    {
        $day = Weekday::from((int) $decision['weekday']);
        $minutes = (int) $decision['duration_minutes'];

        return ClassSlot::overlapping($request->classGroup, $day, $decision['start_time'], $minutes)
            ?? collect($planned)->first(fn (ClassSlot $slot): bool => $slot->overlaps($day, $decision['start_time'], $minutes));
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function plannedSlot(TutorClassRequest $request, array $decision): ClassSlot
    {
        $slot = new ClassSlot([
            'weekday' => (int) $decision['weekday'],
            'start_time' => $decision['start_time'],
            'duration_minutes' => (int) $decision['duration_minutes'],
        ]);

        return $slot->setRelation('classGroup', $request->classGroup)->setRelation('subject', $request->subject);
    }
}
