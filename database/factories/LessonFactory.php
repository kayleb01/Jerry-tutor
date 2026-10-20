<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state. The lesson belongs to a slot of its class (the class's first
     * slot, or a new one) and copies that slot's tutor. Passing class_slot_id alone picks its class.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->addDay()->setTime(9, 0);

        return [
            'class_group_id' => fn (array $attributes) => self::givenSlot($attributes)?->class_group_id ?? ClassGroup::factory(),
            'class_slot_id' => fn (array $attributes) => ClassSlot::withTrashed()
                ->where('class_group_id', $attributes['class_group_id'])
                ->orderBy('id')
                ->value('id') ?? ClassSlot::factory()->state(['class_group_id' => $attributes['class_group_id']]),
            'tutor_id' => fn (array $attributes) => ClassSlot::withTrashed()->find($attributes['class_slot_id'])->tutor_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'status' => LessonStatus::Scheduled,
        ];
    }

    /**
     * The slot passed in as class_slot_id, if one was.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function givenSlot(array $attributes): ?ClassSlot
    {
        $slot = $attributes['class_slot_id'] ?? null;

        return match (true) {
            $slot instanceof ClassSlot => $slot,
            is_int($slot), is_string($slot) => ClassSlot::withTrashed()->find($slot),
            default => null,
        };
    }
}
