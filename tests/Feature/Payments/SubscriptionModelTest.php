<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['payments.grace_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->parent = User::factory()->guardian()->create();
    $this->child = User::factory()->student()->create();
    $this->parent->linkChild($this->child, 'Mother');
});

it('gives staff quiz access and students access only through a live, paid-up subscription', function (?string $state, ?string $periodEnds, bool $access) {
    if ($state !== null) {
        Subscription::factory()->{$state}()->forStudents($this->child)->create([
            'payer_id' => $this->parent->id,
            'current_period_ends_at' => $periodEnds ? CarbonImmutable::parse($periodEnds, 'UTC') : null,
        ]);
    }

    expect($this->child->fresh()->hasQuizAccess())->toBe($access);
})->with([
    'no subscription' => [null, null, false],
    'active, paid up' => ['active', '2026-11-01 00:00:00', true],
    'active, inside grace' => ['active', '2026-10-10 00:00:00', true],
    'past due, inside grace' => ['pastDue', '2026-10-09 12:00:01', true],
    'past due, grace over' => ['pastDue', '2026-10-08 11:59:59', false],
    'cancelled, period still running' => ['cancelled', '2026-11-01 00:00:00', true],
    'expired' => ['expired', '2026-11-01 00:00:00', false],
    'pending' => ['pending', null, false],
]);

it('always lets admins and tutors in', function () {
    expect(User::factory()->admin()->create()->hasQuizAccess())->toBeTrue()
        ->and(User::factory()->tutor()->create()->hasQuizAccess())->toBeTrue()
        ->and($this->parent->hasQuizAccess())->toBeFalse();
});

it('lets one family subscription cover several children', function () {
    $sibling = User::factory()->student()->create();
    $this->parent->linkChild($sibling, 'Mother');
    $subscription = Subscription::factory()->active()->forStudents($this->child, $sibling)->create(['payer_id' => $this->parent->id]);

    expect($subscription->students->pluck('id')->all())->toEqualCanonicalizing([$this->child->id, $sibling->id])
        ->and($sibling->fresh()->hasQuizAccess())->toBeTrue()
        ->and($this->parent->subscriptions()->count())->toBe(1)
        ->and($this->child->coveringSubscriptions()->live()->count())->toBe(1)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('keeps gateway secrets encrypted at rest', function () {
    $subscription = Subscription::factory()->active()->create(['gateway_authorization' => 'AUTH_secret123', 'gateway_email_token' => 'tok_secret']);
    $row = DB::table('subscriptions')->find($subscription->id);

    expect($row->gateway_authorization)->not->toContain('AUTH_secret123')
        ->and($row->gateway_email_token)->not->toContain('tok_secret')
        ->and($subscription->fresh()->gateway_authorization)->toBe('AUTH_secret123');
});

it('seeds a Standard and a Family plan, once', function () {
    config(['payments.plans.family.max_students' => 4]);
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    expect(Plan::orderBy('sort_order')->get(['slug', 'max_students', 'currency', 'interval'])->toArray())->toBe([
        ['slug' => 'standard', 'max_students' => 1, 'currency' => 'NGN', 'interval' => 'monthly'],
        ['slug' => 'family', 'max_students' => 4, 'currency' => 'NGN', 'interval' => 'monthly'],
    ])->and(Plan::firstWhere('slug', 'standard')->formattedAmount())->toBe('₦5,000');
});
