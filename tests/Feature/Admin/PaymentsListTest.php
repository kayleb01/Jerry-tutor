<?php

use App\Enums\PaymentGatewayName;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->standard = Plan::factory()->create(['name' => 'Standard membership']);
    $this->family = Plan::factory()->family()->create(['name' => 'Family membership']);
    $this->ngozi = User::factory()->guardian()->create(['name' => 'Ngozi Okafor', 'email' => 'ngozi@example.com']);
    $this->rotimi = User::factory()->guardian()->create(['name' => '=Rotimi Alade', 'email' => 'rotimi@example.com']);
    $standard = Subscription::factory()->active()->create(['payer_id' => $this->ngozi->id, 'plan_id' => $this->standard->id]);
    $family = Subscription::factory()->pastDue()->create(['payer_id' => $this->rotimi->id, 'plan_id' => $this->family->id, 'gateway' => PaymentGatewayName::Flutterwave]);
    $this->paid = Payment::factory()->succeeded()->create(['subscription_id' => $standard->id, 'payer_id' => $this->ngozi->id, 'created_at' => CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), 'paid_at' => CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC')]);
    $this->failed = Payment::factory()->failed('Card expired')->create(['subscription_id' => $family->id, 'payer_id' => $this->rotimi->id, 'gateway' => PaymentGatewayName::Flutterwave, 'amount' => 1200000, 'created_at' => CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC')]);
    Payment::factory()->succeeded()->create(['subscription_id' => $standard->id, 'payer_id' => $this->ngozi->id, 'created_at' => CarbonImmutable::parse('2026-09-13 10:00:00', 'UTC'), 'paid_at' => CarbonImmutable::parse('2026-09-13 10:00:00', 'UTC')]);
});

it('is only for admins', function (string $state) {
    $user = User::factory()->{$state}()->create();
    $this->actingAs($user)->get(route('admin.payments.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.payments.export'))->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists every payment, newest first, with filters', function () {
    $this->actingAs($this->admin)->get(route('admin.payments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/payments/index')
            ->has('payments.data', 3)
            ->where('payments.data.0.id', $this->paid->id)
            ->where('payments.data.1.failureReason', 'Card expired')
            ->has('plans', 2));

    $ids = fn (array $filters) => collect($this->actingAs($this->admin)->get(route('admin.payments.index', $filters))->viewData('page')['props']['payments']['data'])->pluck('id')->all();

    expect($ids(['status' => 'failed']))->toBe([$this->failed->id])
        ->and($ids(['gateway' => 'flutterwave']))->toBe([$this->failed->id])
        ->and($ids(['plan' => $this->standard->id]))->toHaveCount(2)
        ->and($ids(['search' => 'NGOZI@']))->toHaveCount(2)
        ->and($ids(['from' => '2026-10-01']))->toBe([$this->paid->id, $this->failed->id])
        ->and($ids(['to' => '2026-09-30']))->toHaveCount(1)
        ->and($ids(['status' => 'nope', 'gateway' => 'stripe', 'plan' => 'x']))->toHaveCount(3);
});

it('exports the filtered payments as CSV, neutralising formulas', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.payments.export', ['from' => '2026-10-01']));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toBe('Date,Payer,Email,Plan,"Amount (NGN)",Method,Gateway,Status,"Failure reason",Reference')
        ->and($lines[1])->toContain('2026-10-13,"Ngozi Okafor",ngozi@example.com,Standard,5000.00')
        ->and($lines[2])->toContain("'=Rotimi Alade")
        ->and($lines[2])->toContain('Failed,"Card expired"');
});
