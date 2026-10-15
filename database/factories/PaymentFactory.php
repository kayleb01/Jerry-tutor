<?php

namespace Database\Factories;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'payer_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->payer_id,
            'gateway' => PaymentGatewayName::Paystack,
            'reference' => 'JGA-'.Str::ulid(),
            'amount' => 500000,
            'currency' => 'NGN',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(['status' => PaymentStatus::Succeeded, 'paid_at' => now(), 'card_brand' => 'Visa', 'card_last4' => '4421']);
    }

    public function failed(string $reason = 'Declined'): static
    {
        return $this->state(['status' => PaymentStatus::Failed, 'failure_reason' => $reason]);
    }
}
