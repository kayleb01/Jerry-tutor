<?php

namespace Database\Factories;

use App\Models\ClassGroup;
use App\Models\Subject;
use App\Models\TutorClassRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TutorClassRequest>
 */
class TutorClassRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_id' => User::factory()->pendingTutor(),
            'class_group_id' => ClassGroup::factory(),
            'subject_id' => Subject::factory(),
        ];
    }
}
