<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassGroup>
 */
class ClassGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Year '.fake()->numberBetween(3, 9).' Maths – '.fake()->dayOfWeek(),
            'tutor_id' => User::factory()->tutor(),
            'year_group' => null,
            'location' => fake()->randomElement(['Room 1', 'Room 2', 'Online']),
            'weekday' => fake()->randomElement(Weekday::cases()),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'is_active' => true,
        ];
    }
}
