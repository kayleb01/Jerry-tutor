<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfile>
 */
class StudentProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => Role::Student]),
            'year_group' => fake()->randomElement(YearGroup::cases()),
            'date_of_birth' => null,
            'school_name' => null,
            'challenging_tier_unlocked' => false,
        ];
    }
}
