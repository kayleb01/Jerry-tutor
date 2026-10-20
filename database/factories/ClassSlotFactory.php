<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSlot>
 */
class ClassSlotFactory extends Factory
{
    /**
     * Define the model's default state: a weekly Maths slot with its own tutor.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_group_id' => ClassGroup::factory(),
            'subject_id' => fn (): int => Subject::query()->firstOrCreate(['name' => 'Maths'])->id,
            'tutor_id' => User::factory()->tutor(),
            'weekday' => fake()->randomElement(Weekday::cases()),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'location' => fake()->randomElement(['Room 1', 'Room 2', 'Online']),
        ];
    }
}
