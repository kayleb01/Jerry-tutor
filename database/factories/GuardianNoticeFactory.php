<?php

namespace Database\Factories;

use App\Models\GuardianNotice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuardianNotice>
 */
class GuardianNoticeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'lateness_report_id' => null,
            'sent_by_id' => User::factory()->admin(),
            'note' => fake()->sentence(),
            'acknowledged_at' => null,
            'acknowledged_by_id' => null,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(['acknowledged_at' => now()]);
    }
}
