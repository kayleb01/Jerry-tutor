<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The Standard (one child) and Family (several children) monthly plans. Safe to run repeatedly:
 * existing plans keep their amounts and links, so prices set in Billing settings aren't overwritten.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = config('payments.plans');

        foreach ([
            'standard' => ['name' => 'Standard membership', 'description' => 'One student', 'max_students' => 1, 'sort_order' => 0],
            'family' => ['name' => 'Family membership', 'description' => 'Brothers and sisters together', 'max_students' => $plans['family']['max_students'], 'sort_order' => 1],
        ] as $slug => $attributes) {
            Plan::firstOrCreate(['slug' => $slug], [
                ...$attributes,
                'amount' => $plans[$slug]['amount'],
                'currency' => 'NGN',
                'interval' => 'monthly',
                'paystack_plan_code' => $plans[$slug]['paystack_plan_code'] ?: null,
                'flutterwave_plan_id' => $plans[$slug]['flutterwave_plan_id'] ?: null,
                'is_active' => true,
            ]);
        }
    }
}
