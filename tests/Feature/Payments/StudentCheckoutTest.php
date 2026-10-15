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
    Http::assertSent(fn (ClientRequest $request) => ($request->data()['email'] ?? null) === 'ada.home@example.com');
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

    expect(Subscription::sole()->billing_email)->toBe('ada@example.com');
    Http::assertSent(fn (ClientRequest $request) => ($request->data()['email'] ?? null) === 'ada@example.com');
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

it('keeps each payment email to one payer, so the gateway never mixes up two subscriptions', function (Closure $existing, array $attempt, string $field) {
    $existing->call($this);

    $this->actingAs($attempt['as']->call($this))
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$attempt['for']->call($this)->id], ...$attempt['data']])
        ->assertSessionHasErrors([$field]);

    expect(Subscription::where('status', SubscriptionStatus::Pending)->count())->toBe(0);
})->with([
    'student using the parent\'s email while the parent pays for a sibling' => [
        function () {
            $obi = User::factory()->student()->create();
            $this->parent->linkChild($obi, 'Mother');
            Subscription::factory()->active()->forStudents($obi)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id, 'billing_email' => 'ngozi@example.com']);
        },
        ['as' => fn () => $this->ada, 'for' => fn () => $this->ada, 'data' => ['email' => 'NGOZI@example.com']],
        'email',
    ],
    'parent whose email a child already pays with' => [
        fn () => Subscription::factory()->active()->forStudents($this->ada)->create(['payer_id' => $this->ada->id, 'plan_id' => $this->standard->id, 'billing_email' => 'ngozi@example.com']),
        ['as' => fn () => $this->parent, 'for' => function () {
            $obi = User::factory()->student()->create();
            $this->parent->linkChild($obi, 'Mother');

            return $obi;
        }, 'data' => []],
        'students',
    ],
]);

it('only lets payers without an account email choose a receipt email', function () {
    $this->actingAs($this->parent)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id], 'email' => 'someone@example.com'])
        ->assertSessionHasErrors(['email']);
});

it('does not sell a family plan to a student paying for themselves', function () {
    $this->actingAs($this->ada)
        ->post(route('billing.checkout'), ['plan' => $this->family->id, 'students' => [$this->ada->id], 'email' => 'ada@example.com'])
        ->assertSessionHasErrors(['students' => 'The Family membership is for parents. Choose a plan for one student.']);
});

it('waits while someone else is paying for the same child instead of cancelling their checkout', function () {
    $this->actingAs($this->parent)->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id]]);
    $parents = Subscription::sole();

    $this->actingAs($this->ada)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id], 'email' => 'ada@example.com'])
        ->assertSessionHasErrors(['students' => 'Ngozi is paying for you right now. If that payment doesn’t go through, try again in 30 minutes.']);

    expect($parents->fresh()->status)->toBe(SubscriptionStatus::Pending)
        ->and(Subscription::count())->toBe(1);

    $this->actingAs($this->ada)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.0.state', 'pending')->where('children.0.note', 'Ngozi started paying for you')->where('children.0.pendingOther', true));
    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('children.0.note', 'Waiting for the payment to be confirmed')->where('children.0.pendingOther', false));
});

it('lets a payer replace their own unfinished checkout once someone else\'s has gone stale', function () {
    $this->actingAs($this->parent)->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id]]);
    $this->travel(31)->minutes();

    $this->actingAs($this->ada)
        ->post(route('billing.checkout'), ['plan' => $this->standard->id, 'students' => [$this->ada->id], 'email' => 'ada@example.com'])
        ->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

    expect(Subscription::where('payer_id', $this->parent->id)->sole()->status)->toBe(SubscriptionStatus::Pending);
});
