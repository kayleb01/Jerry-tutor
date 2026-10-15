<?php

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['payments.paystack.secret_key' => 'sk_test_paystack']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->plan = Plan::factory()->create(['amount' => 500000]);
    $this->subscription = Subscription::factory()->pastDue()->create([
        'plan_id' => $this->plan->id, 'gateway' => PaymentGatewayName::Paystack, 'gateway_authorization' => 'AUTH_x',
        'gateway_subscription_code' => 'SUB_vsyqdmlzble3uii',
    ]);
});

it('will not retry again while the last retry is still processing', function () {
    $pending = paymentFixture('paystack', 'verify-success');
    $pending['data']['status'] = 'ongoing';
    Http::fake(['*' => Http::response($pending)]);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.message', 'The payment is still processing. It will show here once it clears.');
    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.message', 'A payment for this subscription is still processing. Wait for it to clear before trying again.');

    Http::assertSentCount(1);
    expect($this->subscription->payments()->where('status', PaymentStatus::Pending)->count())->toBe(1);

    $this->actingAs($this->admin)->get(route('admin.subscriptions.index'))
        ->assertInertia(fn (Assert $page) => $page->where('attention.0.processing', true));
});

it('treats an unreachable gateway as an unknown outcome, not a decline', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.type', 'error');

    expect($this->subscription->payments()->sole()->status)->toBe(PaymentStatus::Pending);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.message', 'A payment for this subscription is still processing. Wait for it to clear before trying again.');
});

it('lets a new retry through once an old one has been stuck for half an hour', function () {
    Http::fake(['*' => Http::response(paymentFixture('paystack', 'verify-success'))]);
    Payment::factory()->create(['subscription_id' => $this->subscription->id, 'created_at' => now()->subMinutes(31)]);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('will not pause a family whose payment has since gone through', function () {
    $this->subscription->update(['status' => SubscriptionStatus::Active]);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.pause', $this->subscription))
        ->assertInertiaFlash('toast.message', 'This payment has since gone through, so access stays on.');

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('keeps the date a subscription was first cancelled', function () {
    $this->subscription->update(['status' => SubscriptionStatus::Active, 'cancel_at_period_end' => true, 'cancel_requested_at' => CarbonImmutable::parse('2026-09-28 10:00:00', 'UTC')]);
    $body = paymentFixtureJson('paystack', 'event-disable');

    $this->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($this->subscription->fresh())
        ->status->toBe(SubscriptionStatus::Cancelled)
        ->cancel_requested_at->toDateTimeString()->toBe('2026-09-28 10:00:00');
});
