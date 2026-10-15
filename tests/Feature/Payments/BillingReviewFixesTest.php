<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['payments.default' => 'paystack', 'payments.paystack.secret_key' => 'sk_test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->plan = Plan::factory()->create(['name' => 'Standard membership', 'paystack_plan_code' => 'PLN_standard']);
    $this->parent = User::factory()->guardian()->create();
    $this->ada = User::factory()->student()->create(['name' => 'Ada']);
    $this->parent->linkChild($this->ada, 'Mother');
});

it('closes the payment of a checkout that a new one replaces', function () {
    Http::fake(['*' => Http::response(paymentFixture('paystack', 'initialize'))]);
    $stale = Subscription::factory()->pending()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->plan->id]);
    $stalePayment = Payment::factory()->create(['subscription_id' => $stale->id, 'payer_id' => $this->parent->id]);

    $this->actingAs($this->parent)->post(route('billing.checkout'), ['plan' => $this->plan->id, 'students' => [$this->ada->id]]);

    expect($stalePayment->fresh())->status->toBe(PaymentStatus::Failed)->failure_reason->toBe('Checkout not completed');
});

it('closes the payment of a checkout nobody finished', function () {
    $abandoned = Subscription::factory()->pending()->create(['created_at' => now()->subHours(30)]);
    $payment = Payment::factory()->create(['subscription_id' => $abandoned->id]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect($payment->fresh())->status->toBe(PaymentStatus::Failed)->failure_reason->toBe('Checkout not completed');
});

it('shows Billing in the menu for every student', function () {
    $selfPaying = User::factory()->student()->create();

    $this->actingAs($selfPaying)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('billing.selfPay', true));
    $this->actingAs($this->ada)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('billing.selfPay', true));
    $this->actingAs($this->parent)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('billing.selfPay', false));
});

it('only offers a new card for a subscription that is still renewing', function () {
    Http::fake();
    $cancelling = Subscription::factory()->active()->create(['payer_id' => $this->parent->id, 'cancel_at_period_end' => true, 'gateway_subscription_code' => 'SUB_x']);

    $this->actingAs($this->parent)->get(route('billing.subscriptions.card', $cancelling))
        ->assertInertiaFlash('toast.type', 'error');

    Http::assertNothingSent();
});

it('lists a subscription that needs attention first', function () {
    $healthy = Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->plan->id]);
    $failing = Subscription::factory()->pastDue()->create(['payer_id' => $this->parent->id, 'plan_id' => Plan::factory()->family()->create()->id]);

    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('subscriptions.0.id', $failing->id)->where('subscriptions.1.id', $healthy->id));
});
