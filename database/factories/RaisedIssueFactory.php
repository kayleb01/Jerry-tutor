<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\RaisedIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaisedIssue>
 */
class RaisedIssueFactory extends Factory
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
            'tutor_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])->tutor_id,
            'raised_by_id' => User::factory()->admin(),
            'note' => fake()->sentence(),
            'acknowledged_at' => null,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(['acknowledged_at' => now()]);
    }
}
