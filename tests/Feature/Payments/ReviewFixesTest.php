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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'payments.default' => 'paystack',
        'payments.paystack.secret_key' => 'sk_test_paystack',
        'payments.flutterwave.secret_key' => 'FLWSECK_TEST',
        'payments.flutterwave.secret_hash' => 'my-flw-hash',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->standard = Plan::factory()->create(['name' => 'Standard membership', 'amount' => 500000, 'paystack_plan_code' => 'PLN_standard', 'flutterwave_plan_id' => '61427']);
    $this->family = Plan::factory()->family(4)->create(['amount' => 1200000, 'paystack_plan_code' => 'PLN_family', 'flutterwave_plan_id' => '61500']);
    $this->parent = User::factory()->guardian()->create(['email' => 'ngozi@example.com']);
    $this->ada = User::factory()->student()->create(['name' => 'Ada']);
    $this->obi = User::factory()->student()->create(['name' => 'Obi']);
    $this->parent->linkChild($this->ada, 'Mother');
    $this->parent->linkChild($this->obi, 'Mother');
});

function sendWebhook(string $gateway, array|string $payload)
{
    $body = is_string($payload) ? $payload : json_encode($payload);
    $headers = $gateway === 'paystack'
        ? ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack')]
        : ['HTTP_VERIF_HASH' => 'my-flw-hash'];

    return test()->call('POST', '/webhooks/'.$gateway, [], [], [], [...$headers, 'CONTENT_TYPE' => 'application/json'], $body);
}

it('links Paystack\'s new subscription to the checkout on that plan, never to the family\'s other subscription', function () {
    $standing = Subscription::factory()->active()->forStudents($this->ada)->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->standard->id,
        'gateway_customer_code' => 'CUS_xnxdt6s1zg1f4nx', 'gateway_subscription_code' => 'SUB_standing', 'gateway_email_token' => 'tok_standing',
    ]);
    $new = Subscription::factory()->active()->forStudents($this->obi)->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->family->id,
        'gateway_customer_code' => 'CUS_xnxdt6s1zg1f4nx', 'gateway_subscription_code' => null,
    ]);
    $event = paymentFixture('paystack', 'event-subscription-create');
    $event['data']['plan']['plan_code'] = 'PLN_family';

    sendWebhook('paystack', $event)->assertOk();

    expect($standing->fresh())->gateway_subscription_code->toBe('SUB_standing')->gateway_email_token->toBe('tok_standing')
        ->and($new->fresh()->gateway_subscription_code)->toBe('SUB_vsyqdmlzble3uii');
});

it('waits rather than guessing when Paystack\'s new subscription arrives before its first charge', function () {
    $standing = Subscription::factory()->active()->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->standard->id,
        'gateway_customer_code' => 'CUS_xnxdt6s1zg1f4nx', 'gateway_subscription_code' => 'SUB_standing',
    ]);

    try {
        sendWebhook('paystack', paymentFixtureJson('paystack', 'event-subscription-create'));
    } catch (Throwable) {
        // The queued job rethrows so it can retry; on the sync queue that surfaces here.
    }

    expect($standing->fresh()->gateway_subscription_code)->toBe('SUB_standing')
        ->and(WebhookEvent::sole()->error)->toContain('No Paystack subscription matches');
});

it('does not fall back to another subscription for an event naming an unknown Paystack subscription', function () {
    $standing = Subscription::factory()->active()->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->standard->id,
        'gateway_customer_code' => 'CUS_xnxdt6s1zg1f4nx', 'gateway_subscription_code' => 'SUB_standing',
        'current_period_ends_at' => CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'),
    ]);

    try {
        sendWebhook('paystack', paymentFixtureJson('paystack', 'event-invoice-update'));
    } catch (Throwable) {
    }

    expect($standing->fresh()->current_period_ends_at->toDateTimeString())->toBe('2026-11-15 12:00:00')
        ->and($standing->payments()->count())->toBe(0);
});

it('lets a family hold only one live subscription per plan', function () {
    Http::fake(['*' => Http::response(paymentFixture('paystack', 'initialize'))]);
    Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);

    $this->actingAs($this->parent)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->obi->id]])
        ->assertSessionHasErrors(['students' => 'You already have a Standard membership. Choose the Family membership to cover more children.']);
});

it('gives a Flutterwave renewal to the subscription whose plan matches the amount', function () {
    $standard = Subscription::factory()->active()->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->standard->id, 'gateway' => PaymentGatewayName::Flutterwave,
        'gateway_customer_code' => '215604089', 'current_period_ends_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
    ]);
    $family = Subscription::factory()->active()->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->family->id, 'gateway' => PaymentGatewayName::Flutterwave,
        'gateway_customer_code' => '215604089', 'current_period_ends_at' => CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'),
    ]);
    $renewal = paymentFixture('flutterwave', 'event-charge-renewal');
    Http::fake(['api.flutterwave.com/v3/transactions/288300555/verify' => Http::response(['status' => 'success', 'data' => $renewal['data']])]);

    sendWebhook('flutterwave', $renewal)->assertOk();

    expect($standard->fresh()->current_period_ends_at->toDateTimeString())->toBe('2026-11-15 12:00:00')
        ->and($family->fresh()->current_period_ends_at->toDateTimeString())->toBe('2026-10-20 12:00:00');
});

it('re-checks a Flutterwave webhook with Flutterwave before giving access', function () {
    $subscription = Subscription::factory()->pending()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id, 'gateway' => PaymentGatewayName::Flutterwave]);
    Payment::factory()->create(['subscription_id' => $subscription->id, 'gateway' => PaymentGatewayName::Flutterwave, 'reference' => 'JGA-01JTESTREFERENCE']);
    $verified = paymentFixture('flutterwave', 'verify-failed');
    $verified['data']['id'] = 288200108;
    Http::fake(['api.flutterwave.com/v3/transactions/288200108/verify' => Http::response($verified), '*' => Http::response(['status' => 'success', 'data' => []])]);

    sendWebhook('flutterwave', paymentFixtureJson('flutterwave', 'event-charge-completed'))->assertOk();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Pending)
        ->and(Payment::sole()->status)->not->toBe(PaymentStatus::Succeeded)
        ->and($this->ada->fresh()->hasQuizAccess())->toBeFalse();
});

it('treats a renewal that reuses the first payment\'s reference as a new payment', function () {
    $subscription = Subscription::factory()->active()->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->standard->id, 'gateway' => PaymentGatewayName::Flutterwave,
        'gateway_customer_code' => '215604089', 'current_period_ends_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
    ]);
    Payment::factory()->succeeded()->create(['subscription_id' => $subscription->id, 'gateway' => PaymentGatewayName::Flutterwave, 'reference' => 'JGA-01JTESTREFERENCE', 'gateway_transaction_id' => '288200108']);
    $renewal = paymentFixture('flutterwave', 'event-charge-completed');
    $renewal['data']['id'] = 288999001;
    Http::fake(['api.flutterwave.com/v3/transactions/288999001/verify' => Http::response(['status' => 'success', 'data' => $renewal['data']])]);

    sendWebhook('flutterwave', $renewal)->assertOk();

    expect($subscription->payments()->count())->toBe(2)
        ->and($subscription->fresh()->current_period_ends_at->toDateTimeString())->toBe('2026-11-15 12:00:00');
});

it('tells the parent a payment is still processing instead of calling it declined', function () {
    $verified = paymentFixture('paystack', 'verify-success');
    $verified['data']['status'] = 'ongoing';
    Http::fake(['*' => Http::response($verified)]);
    $subscription = Subscription::factory()->pending()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);
    Payment::factory()->create(['subscription_id' => $subscription->id, 'reference' => 'JGA-01JTESTREFERENCE']);

    $this->actingAs($this->parent)
        ->get(route('billing.callback', ['paystack', 'reference' => 'JGA-01JTESTREFERENCE']))
        ->assertInertiaFlash('toast.message', "Your payment is still being processed. We'll switch Ada's access on as soon as it clears.");

    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('raises the alarm when a payment does not match its plan', function () {
    Log::spy();
    $verified = paymentFixture('paystack', 'verify-success');
    $verified['data']['amount'] = 600000;
    Http::fake(['*' => Http::response($verified)]);
    $subscription = Subscription::factory()->pending()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);
    Payment::factory()->create(['subscription_id' => $subscription->id, 'reference' => 'JGA-01JTESTREFERENCE']);

    $this->actingAs($this->parent)->get(route('billing.callback', ['paystack', 'reference' => 'JGA-01JTESTREFERENCE']));

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'did not match its plan'))->once();
});
