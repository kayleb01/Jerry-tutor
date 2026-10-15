<?php

namespace Database\Factories;

use App\Enums\QuizAttemptStatus;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'quiz_id' => Quiz::factory(),
            'status' => QuizAttemptStatus::InProgress,
            'started_at' => now(),
            'max_score' => 10,
        ];
    }

    public function submitted(int $score, int $max = 10): static
    {
        return $this->state(['status' => QuizAttemptStatus::Submitted, 'submitted_at' => now(), 'score' => $score, 'max_score' => $max, 'duration_seconds' => 300]);
    }
}
