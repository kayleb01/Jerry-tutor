<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoSubscriptionSeeder;
use Database\Seeders\PlanSeeder;

it('gives the demo families a realistic mix of subscriptions, once', function () {
    User::factory()->admin()->create(['email' => 'admin@academy.test']);
    $this->seed([PlanSeeder::class, DemoDataSeeder::class]);
    $this->seed(DemoSubscriptionSeeder::class);
    $this->seed(DemoSubscriptionSeeder::class);

    $statuses = Subscription::query()->pluck('status');

    expect($statuses->filter(fn ($status) => $status === SubscriptionStatus::Active)->count())->toBeGreaterThan(3)
        ->and($statuses->contains(SubscriptionStatus::PastDue))->toBeTrue()
        ->and(Subscription::whereNotNull('cancel_requested_at')->count())->toBeGreaterThan(0)
        ->and(Payment::where('status', PaymentStatus::Failed)->count())->toBeGreaterThan(0)
        ->and(Payment::where('status', PaymentStatus::Succeeded)->count())->toBeGreaterThan(5)
        ->and(Subscription::has('students', '>', 1)->exists())->toBeTrue();
});

it('leaves a real database alone', function () {
    User::factory()->admin()->create(['email' => 'owner@school.example']);
    $this->seed(DemoSubscriptionSeeder::class);

    expect(Subscription::count())->toBe(0);
});
