<?php

use App\Enums\PaymentGatewayName;
use App\Models\Subscription;
use App\Payments\FlutterwaveGateway;
use App\Payments\PaymentGatewayManager;
use App\Payments\PaystackGateway;

it('resolves both drivers and the default from config', function () {
    $manager = app(PaymentGatewayManager::class);

    config(['payments.default' => 'paystack']);
    expect($manager->driver())->toBeInstanceOf(PaystackGateway::class)
        ->and($manager->driver('flutterwave'))->toBeInstanceOf(FlutterwaveGateway::class)
        ->and($manager->driver('flutterwave')->name())->toBe(PaymentGatewayName::Flutterwave)
        ->and($manager->defaultGateway())->toBe(PaymentGatewayName::Paystack);
});

it('sends an existing subscription to its own gateway after the default changes', function () {
    $subscription = Subscription::factory()->active()->create(['gateway' => PaymentGatewayName::Flutterwave]);
    config(['payments.default' => 'paystack']);

    expect(app(PaymentGatewayManager::class)->for($subscription))->toBeInstanceOf(FlutterwaveGateway::class);
});
