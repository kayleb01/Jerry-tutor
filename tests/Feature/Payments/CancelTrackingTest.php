<?php

use App\Enums\PaymentGatewayName;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['payments.paystack.secret_key' => 'sk_test_paystack']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

it('records when a payer cancels', function () {
    Http::fake(['*' => Http::response(['status' => true])]);
    $parent = User::factory()->guardian()->create();
    $subscription = Subscription::factory()->active()->create(['payer_id' => $parent->id, 'gateway_email_token' => 'tok', 'gateway_subscription_code' => 'SUB_x']);

    $this->actingAs($parent)->post(route('billing.subscriptions.cancel', $subscription), ['reason' => 'Exams finished']);

    expect($subscription->fresh()->cancel_requested_at->toDateTimeString())->toBe('2026-10-15 12:00:00');
});

it('records when Paystack stops or disables a subscription', function (string $fixture) {
    $subscription = Subscription::factory()->active()->create(['plan_id' => Plan::factory()->create()->id, 'gateway' => PaymentGatewayName::Paystack, 'gateway_subscription_code' => 'SUB_vsyqdmlzble3uii']);
    $body = paymentFixtureJson('paystack', $fixture);

    $this->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($subscription->fresh()->cancel_requested_at?->toDateTimeString())->toBe('2026-10-15 12:00:00');
})->with(['event-not-renew', 'event-disable']);
