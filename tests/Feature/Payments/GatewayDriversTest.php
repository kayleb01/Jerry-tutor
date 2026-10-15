<?php

use App\Enums\PaymentGatewayName;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Data\GatewayEventType;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\FlutterwaveGateway;
use App\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'payments.paystack.secret_key' => 'sk_test_paystack',
        'payments.flutterwave.secret_key' => 'FLWSECK_TEST-flutterwave',
        'payments.flutterwave.secret_hash' => 'my-flw-hash',
    ]);
    $this->plan = Plan::factory()->create(['amount' => 500000, 'paystack_plan_code' => 'PLN_standard', 'flutterwave_plan_id' => '61427']);
    $this->payer = User::factory()->guardian()->create(['name' => 'Ngozi Okafor', 'email' => 'ngozi@example.com']);
});

function gatewayDriver(string $gateway)
{
    return app(PaymentGatewayManager::class)->driver($gateway);
}

/**
 * A webhook request signed the way each gateway signs it.
 */
function signedWebhook(string $gateway, string $body, ?string $signature = null): Request
{
    $headers = $gateway === 'paystack'
        ? ['HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, 'sk_test_paystack')]
        : ['HTTP_VERIF_HASH' => $signature ?? 'my-flw-hash'];

    return Request::create('/webhooks/'.$gateway, 'POST', server: [...$headers, 'CONTENT_TYPE' => 'application/json'], content: $body);
}

it('fetches a plan and reports its amount in kobo', function (string $gateway, string $code, string $url) {
    Http::fake([$url => Http::response(paymentFixture($gateway, 'plan'))]);

    $plan = gatewayDriver($gateway)->fetchPlan($code);

    expect($plan->amount)->toBe(500000)
        ->and($plan->currency)->toBe('NGN')
        ->and($plan->interval)->toBe('monthly')
        ->and($plan->active)->toBeTrue();
})->with([
    'paystack' => ['paystack', 'PLN_standard', 'api.paystack.co/plan/PLN_standard'],
    'flutterwave' => ['flutterwave', '61427', 'api.flutterwave.com/v3/payment-plans/61427'],
]);

it('says when a plan code is unknown', function (string $gateway, string $url, string $message) {
    Http::fake([$url => Http::response(['status' => false, 'message' => 'Plan not found'], 404)]);

    expect(fn () => gatewayDriver($gateway)->fetchPlan('nope'))->toThrow(GatewayException::class, $message);
})->with([
    'paystack' => ['paystack', 'api.paystack.co/plan/nope', "Paystack doesn't recognise that plan code."],
    'flutterwave' => ['flutterwave', 'api.flutterwave.com/v3/payment-plans/nope', "Flutterwave doesn't recognise that plan id."],
]);

it('starts a hosted checkout, sending kobo to Paystack and naira to Flutterwave', function (string $gateway, string $url, string $fixture, string $redirect, Closure $assertBody) {
    Http::fake([$url => Http::response(paymentFixture($gateway, $fixture))]);
    $subscription = Subscription::factory()->create(['payer_id' => $this->payer->id, 'plan_id' => $this->plan->id, 'gateway' => PaymentGatewayName::from($gateway)]);
    $payment = Payment::factory()->create(['subscription_id' => $subscription->id, 'reference' => 'JGA-01JTESTREFERENCE', 'gateway' => PaymentGatewayName::from($gateway)]);

    expect(gatewayDriver($gateway)->startCheckout($subscription, $payment, 'https://academy.test/billing/callback/'.$gateway))->toBe($redirect);

    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization') && $assertBody($request->data()));
})->with([
    'paystack' => ['paystack', 'api.paystack.co/transaction/initialize', 'initialize', 'https://checkout.paystack.com/0peioxfhpn', fn () => fn (array $body) => $body['amount'] === 500000
        && $body['plan'] === 'PLN_standard'
        && $body['reference'] === 'JGA-01JTESTREFERENCE'
        && $body['email'] === 'ngozi@example.com'
        && $body['callback_url'] === 'https://academy.test/billing/callback/paystack'],
    'flutterwave' => ['flutterwave', 'api.flutterwave.com/v3/payments', 'payments', 'https://checkout.flutterwave.com/v3/hosted/pay/f524c1196ffda5556341', fn () => fn (array $body) => $body['amount'] === 5000
        && $body['currency'] === 'NGN'
        && $body['payment_plan'] === '61427'
        && $body['tx_ref'] === 'JGA-01JTESTREFERENCE'
        && $body['customer']['email'] === 'ngozi@example.com'
        && $body['redirect_url'] === 'https://academy.test/billing/callback/flutterwave'],
]);

it('verifies a payment and maps it to kobo with the card details', function (string $gateway, string $url, string $brand, string $last4, string $customer, string $authorization) {
    Http::fake([$url => Http::response(paymentFixture($gateway, 'verify-success'))]);

    $payment = gatewayDriver($gateway)->verify('JGA-01JTESTREFERENCE');

    expect($payment)->toMatchObject([
        'reference' => 'JGA-01JTESTREFERENCE',
        'succeeded' => true,
        'amount' => 500000,
        'currency' => 'NGN',
        'customerCode' => $customer,
        'authorization' => $authorization,
        'cardBrand' => $brand,
        'cardLast4' => $last4,
    ])->and($payment->paidAt?->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-15T12:00:05Z')->toIso8601String());
})->with([
    'paystack' => ['paystack', 'api.paystack.co/transaction/verify/JGA-01JTESTREFERENCE', 'Visa', '4421', 'CUS_xnxdt6s1zg1f4nx', 'AUTH_8dfhjjdt'],
    'flutterwave' => ['flutterwave', 'api.flutterwave.com/v3/transactions/verify_by_reference*', 'Mastercard', '0907', '215604089', 'flw-t1nf-f9b3bf384cd30d6fca42b6df9d27bd2f-m03k'],
]);

it('reports a failed payment with the reason', function (string $gateway, string $url, string $reason) {
    Http::fake([$url => Http::response(paymentFixture($gateway, 'verify-failed'))]);

    $payment = gatewayDriver($gateway)->verify('JGA-01JTESTREFERENCE');

    expect($payment->succeeded)->toBeFalse()
        ->and($payment->failureReason)->toBe($reason)
        ->and($payment->authorization)->toBeNull();
})->with([
    'paystack' => ['paystack', 'api.paystack.co/transaction/verify/*', 'Declined'],
    'flutterwave' => ['flutterwave', 'api.flutterwave.com/v3/transactions/*', 'Insufficient funds'],
]);

it('turns an unreachable gateway into a GatewayException', function (string $gateway) {
    Http::fake(['*' => Http::response(['message' => 'Server error'], 500)]);

    expect(fn () => gatewayDriver($gateway)->verify('JGA-01JTESTREFERENCE'))->toThrow(GatewayException::class);
})->with(['paystack', 'flutterwave']);

it('cancels at the gateway that owns the subscription', function (string $gateway, string $method, string $url, array $expected) {
    Http::fake(['*' => Http::response(['status' => true, 'message' => 'ok'])]);
    $subscription = Subscription::factory()->active()->create([
        'gateway' => PaymentGatewayName::from($gateway),
        'gateway_subscription_code' => $gateway === 'paystack' ? 'SUB_vsyqdmlzble3uii' : '4147',
        'gateway_email_token' => 'd7gofp6yppn3qz7',
    ]);

    app(PaymentGatewayManager::class)->for($subscription)->cancel($subscription);

    Http::assertSent(fn (ClientRequest $request) => $request->method() === $method && str_ends_with($request->url(), $url) && array_intersect_assoc($expected, $request->data()) === $expected);
})->with([
    'paystack' => ['paystack', 'POST', '/subscription/disable', ['code' => 'SUB_vsyqdmlzble3uii', 'token' => 'd7gofp6yppn3qz7']],
    'flutterwave' => ['flutterwave', 'PUT', '/subscriptions/4147/cancel', []],
]);

it('rejects webhooks with a bad signature', function (string $gateway) {
    $body = paymentFixtureJson($gateway, $gateway === 'paystack' ? 'event-charge-success' : 'event-charge-completed');

    expect(fn () => gatewayDriver($gateway)->parseWebhook(signedWebhook($gateway, $body, 'forged')))->toThrow(InvalidWebhookSignature::class)
        ->and(fn () => gatewayDriver($gateway)->parseWebhook(Request::create('/webhooks/'.$gateway, 'POST', content: $body)))->toThrow(InvalidWebhookSignature::class);
})->with(['paystack', 'flutterwave']);

it('reads Paystack events', function (string $fixture, ?GatewayEventType $type, array $expected) {
    $event = gatewayDriver('paystack')->parseWebhook(signedWebhook('paystack', paymentFixtureJson('paystack', $fixture)));

    if ($type === null) {
        expect($event)->toBeNull();

        return;
    }

    expect($event->type)->toBe($type)
        ->and($event->eventKey)->toBe(hash('sha256', paymentFixtureJson('paystack', $fixture)))
        ->and($event)->toMatchObject($expected);
})->with([
    'first payment' => ['event-charge-success', GatewayEventType::PaymentSucceeded, ['reference' => 'JGA-01JTESTREFERENCE', 'amount' => 500000, 'currency' => 'NGN', 'customerCode' => 'CUS_xnxdt6s1zg1f4nx', 'authorization' => 'AUTH_8dfhjjdt', 'cardBrand' => 'Visa', 'cardLast4' => '4421']],
    'renewal charge (covered by invoice.update)' => ['event-charge-success-renewal', null, []],
    'subscription created' => ['event-subscription-create', GatewayEventType::SubscriptionCreated, ['subscriptionCode' => 'SUB_vsyqdmlzble3uii', 'emailToken' => 'd7gofp6yppn3qz7', 'customerCode' => 'CUS_xnxdt6s1zg1f4nx']],
    'renewal paid' => ['event-invoice-update', GatewayEventType::PaymentSucceeded, ['reference' => 'T2489023ab', 'subscriptionCode' => 'SUB_vsyqdmlzble3uii', 'amount' => 500000, 'currency' => 'NGN']],
    'renewal failed' => ['event-invoice-failed', GatewayEventType::PaymentFailed, ['reference' => 'T2489099fail', 'subscriptionCode' => 'SUB_vsyqdmlzble3uii', 'failureReason' => 'Insufficient Funds']],
    'not renewing' => ['event-not-renew', GatewayEventType::SubscriptionNotRenewing, ['subscriptionCode' => 'SUB_vsyqdmlzble3uii']],
    'disabled' => ['event-disable', GatewayEventType::SubscriptionCancelled, ['subscriptionCode' => 'SUB_vsyqdmlzble3uii']],
]);

it('reads the period end Paystack gives', function () {
    $event = gatewayDriver('paystack')->parseWebhook(signedWebhook('paystack', paymentFixtureJson('paystack', 'event-invoice-update')));

    expect($event->periodEndsAt->toIso8601String())->toBe(CarbonImmutable::parse('2026-12-15T12:00:00Z')->toIso8601String());
});

it('reads Flutterwave charges, converting naira to kobo', function (string $fixture, GatewayEventType $type, array $expected) {
    $event = gatewayDriver('flutterwave')->parseWebhook(signedWebhook('flutterwave', paymentFixtureJson('flutterwave', $fixture)));

    expect($event->type)->toBe($type)->and($event)->toMatchObject($expected);
})->with([
    'first payment' => ['event-charge-completed', GatewayEventType::PaymentSucceeded, ['reference' => 'JGA-01JTESTREFERENCE', 'eventKey' => '288200108:successful', 'amount' => 500000, 'customerCode' => '215604089', 'cardBrand' => 'Mastercard', 'cardLast4' => '0907']],
    'renewal' => ['event-charge-renewal', GatewayEventType::PaymentSucceeded, ['reference' => 'rave-sub-a8c1e2', 'amount' => 500000]],
    'failed renewal' => ['event-charge-failed', GatewayEventType::PaymentFailed, ['reference' => 'rave-sub-a8c1e3', 'failureReason' => 'Insufficient funds']],
]);

it('ignores Flutterwave events other than charges', function () {
    $event = gatewayDriver('flutterwave')->parseWebhook(signedWebhook('flutterwave', json_encode(['event' => 'transfer.completed', 'data' => ['id' => 1]])));

    expect($event)->toBeNull();
});

it('finds the Flutterwave subscription created by a first payment', function () {
    Http::fake(['api.flutterwave.com/v3/subscriptions*' => Http::response(paymentFixture('flutterwave', 'subscriptions'))]);

    /** @var FlutterwaveGateway $driver */
    $driver = gatewayDriver('flutterwave');

    expect($driver->findSubscriptionId('ngozi@example.com', '61427'))->toBe('4147')
        ->and($driver->findSubscriptionId('ngozi@example.com', '99999'))->toBeNull();

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'email=ngozi%40example.com'));
});
