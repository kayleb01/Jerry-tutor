<?php

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\PaymentFailedMail;
use App\Models\GuardianNotice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['payments.paystack.secret_key' => 'sk_test', 'payments.flutterwave.secret_key' => 'FLW_test', 'payments.grace_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->plan = Plan::factory()->create(['name' => 'Standard membership', 'amount' => 500000]);
    $this->parent = User::factory()->guardian()->create(['name' => 'Halima Kareem', 'email' => 'halima@example.com']);
    $this->child = User::factory()->student()->create(['name' => 'Musa Kareem']);
    $this->parent->linkChild($this->child, 'Mother');
    $this->subscription = Subscription::factory()->pastDue()->forStudents($this->child)->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->plan->id,
        'gateway_authorization' => 'AUTH_8dfhjjdt', 'gateway_customer_code' => 'CUS_xnxdt6s1zg1f4nx',
        'current_period_ends_at' => CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'),
    ]);
    Payment::factory()->failed('Card declined')->create(['subscription_id' => $this->subscription->id, 'payer_id' => $this->parent->id]);
});

it('is only for admins', function (string $action) {
    $this->actingAs($this->parent)->post(route("admin.subscriptions.{$action}", $this->subscription))->assertForbidden();
})->with(['remind', 'pause', 'retry']);

it('emails the parent and leaves a note on their dashboard, once a day', function () {
    Mail::fake();

    $this->actingAs($this->admin)->post(route('admin.subscriptions.remind', $this->subscription))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Emailed Halima Kareem.');

    Mail::assertSent(PaymentFailedMail::class, fn (PaymentFailedMail $mail) => $mail->hasTo('halima@example.com') && $mail->subscription->is($this->subscription));
    expect(GuardianNotice::sole())->student_id->toBe($this->child->id)->note->toContain('update your card')
        ->and($this->subscription->fresh()->payment_reminder_sent_at->toDateTimeString())->toBe('2026-10-15 12:00:00');

    $this->actingAs($this->admin)->post(route('admin.subscriptions.remind', $this->subscription))
        ->assertInertiaFlash('toast.type', 'error');
    Mail::assertSentCount(1);
});

it('pauses access straight away', function () {
    expect($this->child->hasQuizAccess())->toBeTrue();

    $this->actingAs($this->admin)->post(route('admin.subscriptions.pause', $this->subscription))
        ->assertInertiaFlash('toast.message', 'Access paused for Musa Kareem.');

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($this->child->fresh()->hasQuizAccess())->toBeFalse();
});

it('charges the saved card again and restores access', function (string $gateway, string $url, array $response, Closure $assertBody) {
    $this->subscription->update(['gateway' => PaymentGatewayName::from($gateway)]);
    Http::fake([$url => Http::response($response)]);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.message', 'Payment taken. Musa Kareem has access again.');

    $subscription = $this->subscription->fresh();
    $payment = $subscription->payments()->where('status', PaymentStatus::Succeeded)->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_ends_at->toDateTimeString())->toBe('2026-11-15 12:00:00')
        ->and($payment->amount)->toBe(500000)
        ->and($payment->reference)->toStartWith('JGA-');
    Http::assertSent(fn (ClientRequest $request) => $assertBody($request->data(), $payment->reference));
})->with([
    'paystack' => ['paystack', 'api.paystack.co/transaction/charge_authorization', fn () => [
        'status' => true,
        'data' => [...paymentFixture('paystack', 'verify-success')['data'], 'reference' => 'REF_PLACEHOLDER'],
    ], fn () => fn (array $body, string $reference) => $body['authorization_code'] === 'AUTH_8dfhjjdt' && $body['amount'] === 500000 && $body['email'] === 'halima@example.com' && $body['reference'] === $reference && $body['currency'] === 'NGN'],
    'flutterwave' => ['flutterwave', 'api.flutterwave.com/v3/tokenized-charges', fn () => [
        'status' => 'success',
        'data' => [...paymentFixture('flutterwave', 'verify-success')['data'], 'tx_ref' => 'REF_PLACEHOLDER'],
    ], fn () => fn (array $body, string $reference) => $body['token'] === 'AUTH_8dfhjjdt' && $body['amount'] === 5000 && $body['currency'] === 'NGN' && $body['email'] === 'halima@example.com' && $body['tx_ref'] === $reference],
]);

it('reports a second decline without restoring access', function () {
    $declined = paymentFixture('paystack', 'verify-failed');
    $declined['data']['gateway_response'] = 'Insufficient Funds';
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response($declined)]);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $this->subscription))
        ->assertInertiaFlash('toast.message', 'Card declined again: Insufficient Funds');

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($this->subscription->payments()->where('failure_reason', 'Insufficient Funds')->count())->toBe(1);
});

it('cannot retry without a saved card or a failing subscription', function () {
    Http::fake();
    $noCard = Subscription::factory()->pastDue()->create(['gateway_authorization' => null]);
    $healthy = Subscription::factory()->active()->create(['gateway_authorization' => 'AUTH_x']);

    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $noCard))->assertInertiaFlash('toast.type', 'error');
    $this->actingAs($this->admin)->post(route('admin.subscriptions.retry', $healthy))->assertInertiaFlash('toast.type', 'error');
    Http::assertNothingSent();
});

it('writes a clear email with the grace date and a link to Billing', function () {
    $html = (new PaymentFailedMail($this->subscription))->render();

    expect($html)->toContain('Hello Halima Kareem')
        ->toContain('Standard membership')
        ->toContain('card declined')
        ->toContain('Musa Kareem can keep using the quizzes until')
        ->toContain('Saturday 17 October')
        ->toContain(route('billing.index'));
});
