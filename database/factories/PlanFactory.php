<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Standard '.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'amount' => 500000,
            'currency' => 'NGN',
            'interval' => 'monthly',
            'max_students' => 1,
            'paystack_plan_code' => 'PLN_'.Str::lower(Str::random(10)),
            'flutterwave_plan_id' => (string) fake()->unique()->numberBetween(10000, 99999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function family(int $maxStudents = 4): static
    {
        return $this->state(['name' => 'Family membership', 'slug' => 'family-'.Str::lower(Str::random(6)), 'amount' => 1200000, 'max_students' => $maxStudents]);
    }

    public function unlinked(): static
    {
        return $this->state(['paystack_plan_code' => null, 'flutterwave_plan_id' => null]);
    }
}
