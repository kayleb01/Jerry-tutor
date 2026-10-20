<?php

namespace Database\Factories;

use App\Models\ClassGroup;
use App\Models\ClassSlot;
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
            'name' => 'Year '.fake()->numberBetween(3, 9).' – '.fake()->word(),
            'year_group' => null,
            'is_active' => true,
        ];
    }

    /**
     * Give the class a weekly Maths slot (random day, 10:00, 60 minutes), taught by $tutor or a new tutor.
     *
     * @param  array<string, mixed>  $slot  attributes overriding the slot's defaults
     */
    public function withSlot(?User $tutor = null, array $slot = []): static
    {
        return $this->has(
            ClassSlot::factory()->state([
                'tutor_id' => $tutor ?? User::factory()->tutor(),
                ...$slot,
            ]),
            'slots',
        );
    }
}
