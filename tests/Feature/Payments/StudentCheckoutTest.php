<?php

use App\Enums\SubscriptionStatus;
use App\Mail\PaymentFailedMail;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionsOverview;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['payments.default' => 'paystack', 'payments.paystack.secret_key' => 'sk_test']);
    $this->standard = Plan::factory()->create(['name' => 'Standard membership', 'paystack_plan_code' => 'PLN_standard', 'max_students' => 1, 'sort_order' => 0]);
    $this->family = Plan::factory()->family(2)->create(['paystack_plan_code' => 'PLN_family', 'sort_order' => 1]);
    $this->parent = User::factory()->guardian()->create(['name' => 'Ngozi Okafor', 'email' => 'ngozi@example.com']);
    $this->ada = User::factory()->student()->create(['name' => 'Ada Okafor', 'email' => null]);
    $this->parent->linkChild($this->ada, 'Mother');
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(paymentFixture('paystack', 'initialize'))]);
});

it('lets a student with a parent account subscribe for themselves', function () {
    $this->actingAs($this->ada)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('billing.selfPay', true));

    $this->actingAs($this->ada)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id], 'email' => ' Ada.Home@Example.com '])
        ->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

    $subscription = Subscription::sole();

    expect($subscription->payer_id)->toBe($this->ada->id)
        ->and($subscription->billing_email)->toBe('ada.home@example.com')
        ->and($this->ada->fresh()->email)->toBeNull();
    Http::assertSent(fn (ClientRequest $request) => $request['email'] === 'ada.home@example.com');
});

it('asks a student without an email address for one to send receipts to', function (array $email) {
    $this->actingAs($this->ada)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id], ...$email])
        ->assertSessionHasErrors(['email']);

    expect(Subscription::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing' => [[]],
    'not an email' => [['email' => 'ada at home']],
]);

it('uses the account email when the student has one', function () {
    $this->ada->forceFill(['email' => 'ada@example.com', 'email_verified_at' => now()])->save();

    $this->actingAs($this->ada)->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id]])
        ->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

    expect(Subscription::sole()->billing_email)->toBeNull();
    Http::assertSent(fn (ClientRequest $request) => $request['email'] === 'ada@example.com');
});

it('shows a student the one-student plans and whether it needs their email', function () {
    $this->actingAs($this->ada)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('plans', 1)
            ->where('plans.0.id', $this->standard->id)
            ->where('payer', ['isStudent' => true, 'needsEmail' => true]));

    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->has('plans', 2)->where('payer', ['isStudent' => false, 'needsEmail' => false]));
});

it('tells the parent and the student who is paying', function () {
    Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->ada->id, 'plan_id' => $this->standard->id]);

    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.0.state', 'covered')->where('children.0.note', fn (string $note) => str_contains($note, 'paid by Ada')));

    $obi = User::factory()->student()->create(['name' => 'Obi Okafor']);
    $this->parent->linkChild($obi, 'Mother');
    Subscription::factory()->active()->forStudents($obi)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);

    $this->actingAs($obi)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.0.note', fn (string $note) => str_contains($note, 'paid by Ngozi')));
    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.1.note', fn (string $note) => ! str_contains($note, 'paid by')));
});

it('stops the parent paying again for a child who already pays', function () {
    Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->ada->id, 'plan_id' => $this->standard->id]);

    $this->actingAs($this->parent)->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id]])
        ->assertSessionHasErrors(['students' => 'Ada Okafor already has a subscription.']);
});

it('reminds a student payer at their receipt email', function () {
    Mail::fake();
    $subscription = Subscription::factory()->create(['payer_id' => $this->ada->id, 'plan_id' => $this->standard->id, 'status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => now()->subDay(), 'billing_email' => 'ada.home@example.com']);

    expect(app(SubscriptionsOverview::class)->attention()->sole()['canRemind'])->toBeTrue();

    $this->actingAs(User::factory()->admin()->create())->post(route('admin.subscriptions.remind', $subscription))->assertRedirect();

    Mail::assertSent(PaymentFailedMail::class, fn (PaymentFailedMail $mail) => $mail->hasTo('ada.home@example.com'));
});
