<?php

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['payments.grace_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

it('expires subscriptions once the paid period and grace have passed', function (string $state, string $periodEnds, SubscriptionStatus $expected) {
    $subscription = Subscription::factory()->{$state}()->create(['current_period_ends_at' => CarbonImmutable::parse($periodEnds, 'UTC')]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe($expected);
})->with([
    'past due, grace just over' => ['pastDue', '2026-10-08 11:59:00', SubscriptionStatus::Expired],
    'past due, still in grace' => ['pastDue', '2026-10-08 12:01:00', SubscriptionStatus::PastDue],
    'active, gateway never renewed' => ['active', '2026-10-01 00:00:00', SubscriptionStatus::Expired],
    'active, paid up' => ['active', '2026-11-01 00:00:00', SubscriptionStatus::Active],
    'cancelled, period over' => ['cancelled', '2026-10-15 11:00:00', SubscriptionStatus::Expired],
    'cancelled, period running' => ['cancelled', '2026-10-20 00:00:00', SubscriptionStatus::Cancelled],
]);

it('closes checkouts nobody finished within a day', function () {
    $abandoned = Subscription::factory()->pending()->create(['created_at' => now()->subHours(25)]);
    $recent = Subscription::factory()->pending()->create(['created_at' => now()->subHours(2)]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($abandoned->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($recent->fresh()->status)->toBe(SubscriptionStatus::Pending);
});

it('runs every hour', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('subscriptions:expire');
});
