<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->addDay()->setTime(9, 0);

        return [
            'class_group_id' => ClassGroup::factory(),
            'tutor_id' => fn (array $attributes) => ClassGroup::find($attributes['class_group_id'])->tutor_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'status' => LessonStatus::Scheduled,
        ];
    }
}
