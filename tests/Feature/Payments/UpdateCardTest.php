<?php

use App\Enums\PaymentGatewayName;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['payments.paystack.secret_key' => 'sk_test']);
    $this->parent = User::factory()->guardian()->create();
});

it('sends a Paystack payer to Paystack\'s page for changing the card', function () {
    Http::fake(['api.paystack.co/subscription/SUB_abc/manage/link' => Http::response(['status' => true, 'data' => ['link' => 'https://paystack.com/manage/subscriptions/qlgwhpyq1ts9nsw?subscription_token=abc']])]);
    $subscription = Subscription::factory()->active()->create(['payer_id' => $this->parent->id, 'gateway_subscription_code' => 'SUB_abc']);

    $this->actingAs($this->parent)
        ->get(route('billing.subscriptions.card', $subscription))
        ->assertRedirect('https://paystack.com/manage/subscriptions/qlgwhpyq1ts9nsw?subscription_token=abc');
});

it('explains how to change a Flutterwave card', function () {
    Http::fake();
    $subscription = Subscription::factory()->active()->create(['payer_id' => $this->parent->id, 'gateway' => PaymentGatewayName::Flutterwave]);

    $this->actingAs($this->parent)
        ->get(route('billing.subscriptions.card', $subscription))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Flutterwave doesn’t let you swap the card on a subscription. Cancel it here, then subscribe again with the new card once this month ends.');

    Http::assertNothingSent();
});

it('says when Paystack has not set up the subscription yet', function () {
    Http::fake();
    $subscription = Subscription::factory()->active()->create(['payer_id' => $this->parent->id, 'gateway_subscription_code' => null]);

    $this->actingAs($this->parent)
        ->get(route('billing.subscriptions.card', $subscription))
        ->assertInertiaFlash('toast.message', "Paystack hasn't confirmed this subscription yet. Please try again in a few minutes.");
});

it('only lets the payer change the card', function () {
    Http::fake();
    $subscription = Subscription::factory()->active()->create();

    $this->actingAs($this->parent)->get(route('billing.subscriptions.card', $subscription))->assertForbidden();
    Http::assertNothingSent();
});
