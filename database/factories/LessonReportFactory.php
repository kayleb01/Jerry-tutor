<?php

namespace Database\Factories;

use App\Enums\AttendeeRole;
use App\Models\Lesson;
use App\Models\LessonReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonReport>
 */
class LessonReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'author_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])->tutor_id,
            'author_role' => AttendeeRole::Tutor,
            'rating' => fake()->numberBetween(1, 5),
            'went_well' => fake()->sentence(),
            'issues' => null,
            'recommendations' => fake()->sentence(),
            'submitted_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['submitted_at' => null]);
    }
}
