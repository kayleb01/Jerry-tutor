<?php

namespace Database\Factories;

use App\Enums\PaymentGatewayName;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payer_id' => User::factory()->guardian(),
            'plan_id' => Plan::factory(),
            'gateway' => PaymentGatewayName::Paystack,
            'status' => SubscriptionStatus::Pending,
            'current_period_ends_at' => null,
            'cancel_at_period_end' => false,
        ];
    }

    /**
     * The children this subscription covers.
     */
    public function forStudents(User ...$students): static
    {
        return $this->afterCreating(function (Subscription $subscription) use ($students): void {
            $subscription->students()->attach(array_map(fn (User $student): int => $student->id, $students));
        });
    }

    public function pending(): static
    {
        return $this->state(['status' => SubscriptionStatus::Pending]);
    }

    public function active(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(),
            'gateway_customer_code' => 'CUS_'.fake()->bothify('??????##'),
            'gateway_subscription_code' => 'SUB_'.fake()->bothify('??????##'),
            'card_brand' => 'Visa',
            'card_last4' => fake()->numerify('####'),
        ]);
    }

    public function pastDue(): static
    {
        return $this->active()->state(['status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => now()->subDays(2)]);
    }

    public function cancelled(): static
    {
        return $this->active()->state(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
    }

    public function expired(): static
    {
        return $this->active()->state(['status' => SubscriptionStatus::Expired, 'current_period_ends_at' => now()->subMonth()]);
    }
}
