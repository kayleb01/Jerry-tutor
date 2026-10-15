<?php

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'payments.default' => 'paystack',
        'payments.paystack.secret_key' => 'sk_test_paystack',
        'payments.flutterwave.secret_key' => 'FLWSECK_TEST',
        'payments.flutterwave.secret_hash' => 'my-flw-hash',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->plan = Plan::factory()->create(['name' => 'Standard membership', 'amount' => 500000, 'paystack_plan_code' => 'PLN_standard', 'flutterwave_plan_id' => '61427']);
    $this->parent = User::factory()->guardian()->create(['email' => 'ngozi@example.com']);
    $this->ada = User::factory()->student()->create(['name' => 'Ada']);
    $this->parent->linkChild($this->ada, 'Mother');
});

/**
 * A pending checkout with the reference the recorded payloads use.
 */
function pendingCheckout(string $gateway = 'paystack'): Subscription
{
    $subscription = Subscription::factory()->pending()->forStudents(test()->ada)->create([
        'payer_id' => test()->parent->id,
        'plan_id' => test()->plan->id,
        'gateway' => PaymentGatewayName::from($gateway),
    ]);
    Payment::factory()->create([
        'subscription_id' => $subscription->id,
        'payer_id' => test()->parent->id,
        'gateway' => PaymentGatewayName::from($gateway),
        'reference' => 'JGA-01JTESTREFERENCE',
        'amount' => 500000,
    ]);

    return $subscription;
}

function postWebhook(string $gateway, string $body, ?string $signature = null)
{
    $headers = $gateway === 'paystack'
        ? ['X-Paystack-Signature' => $signature ?? hash_hmac('sha512', $body, 'sk_test_paystack')]
        : ['verif-hash' => $signature ?? 'my-flw-hash'];

    return test()->call('POST', '/webhooks/'.$gateway, [], [], [], collect($headers)->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->put('CONTENT_TYPE', 'application/json')->all(), $body);
}

function fakeGateways(array $overrides = []): void
{
    // Overrides replace a default with the same URL pattern but keep its place, so the catch-all stays last.
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response(paymentFixture('paystack', 'verify-success')),
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(paymentFixture('flutterwave', 'verify-success')),
        'api.flutterwave.com/v3/subscriptions*' => Http::response(paymentFixture('flutterwave', 'subscriptions')),
        'api.flutterwave.com/v3/transactions/288200108/verify' => Http::response(paymentFixture('flutterwave', 'verify-success')),
        'api.flutterwave.com/v3/transactions/288300555/verify' => Http::response(['status' => 'success', 'data' => paymentFixture('flutterwave', 'event-charge-renewal')['data']]),
        '*' => Http::response(['status' => true, 'message' => 'ok']),
        ...$overrides,
    ]);
}

it('activates the subscription when the callback verifies a payment', function (string $gateway, string $customer, ?string $subscriptionCode, string $last4) {
    fakeGateways();
    $subscription = pendingCheckout($gateway);

    $this->actingAs($this->parent)
        ->get(route('billing.callback', [$gateway, 'reference' => 'JGA-01JTESTREFERENCE']))
        ->assertRedirect(route('billing.index'))
        ->assertInertiaFlash('toast.message', "You're subscribed. Ada can start their quizzes now.");

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_ends_at->toDateTimeString())->toBe('2026-11-15 12:00:00')
        ->and($subscription->gateway_customer_code)->toBe($customer)
        ->and($subscription->gateway_subscription_code)->toBe($subscriptionCode)
        ->and($subscription->gateway_authorization)->not->toBeNull()
        ->and($subscription->card_last4)->toBe($last4)
        ->and(Payment::sole())->status->toBe(PaymentStatus::Succeeded)
        ->and($this->ada->fresh()->hasQuizAccess())->toBeTrue();
})->with([
    'paystack' => ['paystack', 'CUS_xnxdt6s1zg1f4nx', null, '4421'],
    'flutterwave' => ['flutterwave', '215604089', '4147', '0907'],
]);

it('accepts the Flutterwave redirect, which sends tx_ref', function () {
    fakeGateways();
    pendingCheckout('flutterwave');

    $this->actingAs($this->parent)
        ->get(route('billing.callback', ['flutterwave', 'status' => 'successful', 'tx_ref' => 'JGA-01JTESTREFERENCE', 'transaction_id' => 288200108]))
        ->assertRedirect(route('billing.index'));

    expect(Payment::sole()->status)->toBe(PaymentStatus::Succeeded);
});

it('does not activate on a failed payment', function () {
    fakeGateways(['api.paystack.co/transaction/verify/*' => Http::response(paymentFixture('paystack', 'verify-failed'))]);
    $subscription = pendingCheckout();

    $this->actingAs($this->parent)
        ->get(route('billing.callback', ['paystack', 'reference' => 'JGA-01JTESTREFERENCE']))
        ->assertInertiaFlash('toast.message', "That payment didn't go through: Declined");

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Pending)
        ->and(Payment::sole())->status->toBe(PaymentStatus::Failed)->failure_reason->toBe('Declined')
        ->and($this->ada->fresh()->hasQuizAccess())->toBeFalse();
});

it('refuses a payment whose amount or currency does not match the plan', function (array $override) {
    $verified = paymentFixture('paystack', 'verify-success');
    $verified['data'] = [...$verified['data'], ...$override];
    fakeGateways(['api.paystack.co/transaction/verify/*' => Http::response($verified)]);
    $subscription = pendingCheckout();

    $this->actingAs($this->parent)->get(route('billing.callback', ['paystack', 'reference' => 'JGA-01JTESTREFERENCE']));

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Pending)
        ->and(Payment::sole())->status->toBe(PaymentStatus::Failed)->failure_reason->toBe('Amount or currency did not match the plan');
})->with([
    'too little' => [['amount' => 100]],
    'not naira' => [['currency' => 'USD']],
]);

it('gives 404 for a reference we never issued', function () {
    fakeGateways();

    $this->actingAs($this->parent)->get(route('billing.callback', ['paystack', 'reference' => 'JGA-forged']))->assertNotFound();
    Http::assertNothingSent();
});

it('ends in the same state whichever arrives first, the callback or the webhook', function (bool $webhookFirst) {
    fakeGateways();
    $subscription = pendingCheckout();
    $callback = fn () => $this->actingAs($this->parent)->get(route('billing.callback', ['paystack', 'reference' => 'JGA-01JTESTREFERENCE']));
    $webhook = fn () => postWebhook('paystack', paymentFixtureJson('paystack', 'event-charge-success'))->assertOk();

    if ($webhookFirst) {
        $webhook();
        $callback();
    } else {
        $callback();
        $webhook();
    }

    expect(Payment::count())->toBe(1)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Succeeded)
        ->and($subscription->fresh()->current_period_ends_at->toDateTimeString())->toBe('2026-11-15 12:00:00');
})->with(['webhook first' => true, 'callback first' => false]);

it('ignores a repeated webhook', function () {
    fakeGateways();
    $subscription = Subscription::factory()->active()->forStudents($this->ada)->create([
        'payer_id' => $this->parent->id,
        'plan_id' => $this->plan->id,
        'gateway_subscription_code' => 'SUB_vsyqdmlzble3uii',
        'current_period_ends_at' => CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'),
    ]);

    postWebhook('paystack', paymentFixtureJson('paystack', 'event-invoice-update'))->assertOk();
    postWebhook('paystack', paymentFixtureJson('paystack', 'event-invoice-update'))->assertOk();

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::sole()->processed_at)->not->toBeNull()
        ->and($subscription->payments()->count())->toBe(1)
        ->and($subscription->fresh()->current_period_ends_at->toDateTimeString())->toBe('2026-12-15 12:00:00');
});

it('rejects a webhook with a bad signature and stores nothing', function (string $gateway) {
    pendingCheckout($gateway);
    $body = paymentFixtureJson($gateway, $gateway === 'paystack' ? 'event-charge-success' : 'event-charge-completed');

    postWebhook($gateway, $body, 'forged')->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Pending);
})->with(['paystack', 'flutterwave']);

it('answers events it does not act on without storing them', function () {
    postWebhook('paystack', json_encode(['event' => 'transfer.success', 'data' => []]))->assertOk()->assertJson(['status' => 'ignored']);

    expect(WebhookEvent::count())->toBe(0);
});

it('records Paystack subscription details when the subscription is created', function () {
    fakeGateways();
    $subscription = pendingCheckout();
    postWebhook('paystack', paymentFixtureJson('paystack', 'event-charge-success'))->assertOk();

    postWebhook('paystack', paymentFixtureJson('paystack', 'event-subscription-create'))->assertOk();

    expect($subscription->fresh())
        ->gateway_subscription_code->toBe('SUB_vsyqdmlzble3uii')
        ->gateway_email_token->toBe('d7gofp6yppn3qz7');
});

it('extends the period on a renewal and records the payment', function (string $gateway, string $fixture, string $reference, string $expectedEnd) {
    fakeGateways();
    $subscription = Subscription::factory()->active()->forStudents($this->ada)->create([
        'payer_id' => $this->parent->id,
        'plan_id' => $this->plan->id,
        'gateway' => PaymentGatewayName::from($gateway),
        'gateway_customer_code' => $gateway === 'paystack' ? 'CUS_xnxdt6s1zg1f4nx' : '215604089',
        'gateway_subscription_code' => $gateway === 'paystack' ? 'SUB_vsyqdmlzble3uii' : '4147',
        'current_period_ends_at' => CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'),
    ]);

    postWebhook($gateway, paymentFixtureJson($gateway, $fixture))->assertOk();

    expect($subscription->fresh()->current_period_ends_at->toDateTimeString())->toBe($expectedEnd)
        ->and($subscription->payments()->sole())->reference->toBe($reference)->status->toBe(PaymentStatus::Succeeded)->amount->toBe(500000);
})->with([
    'paystack invoice' => ['paystack', 'event-invoice-update', 'T2489023ab', '2026-12-15 12:00:00'],
    'flutterwave charge' => ['flutterwave', 'event-charge-renewal', 'rave-sub-a8c1e2', '2026-12-15 12:00:00'],
]);

it('marks a subscription past due when a renewal fails, keeping access through the grace period', function (string $gateway, string $fixture) {
    fakeGateways();
    $subscription = Subscription::factory()->active()->forStudents($this->ada)->create([
        'payer_id' => $this->parent->id,
        'plan_id' => $this->plan->id,
        'gateway' => PaymentGatewayName::from($gateway),
        'gateway_customer_code' => $gateway === 'paystack' ? 'CUS_xnxdt6s1zg1f4nx' : '215604089',
        'gateway_subscription_code' => $gateway === 'paystack' ? 'SUB_vsyqdmlzble3uii' : '4147',
        'current_period_ends_at' => CarbonImmutable::parse('2026-10-15 11:00:00', 'UTC'),
    ]);

    postWebhook($gateway, paymentFixtureJson($gateway, $fixture))->assertOk();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->payments()->sole())->status->toBe(PaymentStatus::Failed)->failure_reason->not->toBeNull()
        ->and($this->ada->fresh()->hasQuizAccess())->toBeTrue();
})->with([
    'paystack' => ['paystack', 'event-invoice-failed'],
    'flutterwave' => ['flutterwave', 'event-charge-failed'],
]);

it('follows Paystack when a subscription stops renewing or is disabled', function () {
    fakeGateways();
    $subscription = Subscription::factory()->active()->create(['plan_id' => $this->plan->id, 'gateway_subscription_code' => 'SUB_vsyqdmlzble3uii']);

    postWebhook('paystack', paymentFixtureJson('paystack', 'event-not-renew'))->assertOk();
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();

    postWebhook('paystack', paymentFixtureJson('paystack', 'event-disable'))->assertOk();
    expect($subscription->fresh())->status->toBe(SubscriptionStatus::Cancelled)->cancelled_at->not->toBeNull();
});

it('cancels at the subscription\'s own gateway and keeps access to the period end', function () {
    fakeGateways();
    config(['payments.default' => 'paystack']);
    $subscription = Subscription::factory()->active()->forStudents($this->ada)->create([
        'payer_id' => $this->parent->id,
        'plan_id' => $this->plan->id,
        'gateway' => PaymentGatewayName::Flutterwave,
        'gateway_subscription_code' => '4147',
    ]);

    $this->actingAs($this->parent)
        ->post(route('billing.subscriptions.cancel', $subscription), ['reason' => 'Exams finished'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/subscriptions/4147/cancel'));
    expect($subscription->fresh())
        ->cancel_at_period_end->toBeTrue()
        ->cancellation_reason->toBe('Exams finished')
        ->status->toBe(SubscriptionStatus::Active)
        ->and($this->ada->fresh()->hasQuizAccess())->toBeTrue();
});

it('only lets the payer cancel', function () {
    fakeGateways();
    $subscription = Subscription::factory()->active()->create(['plan_id' => $this->plan->id]);

    $this->actingAs($this->parent)->post(route('billing.subscriptions.cancel', $subscription))->assertForbidden();

    Http::assertNothingSent();
});
