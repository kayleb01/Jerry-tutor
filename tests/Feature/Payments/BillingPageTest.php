<?php

use App\Enums\PaymentGatewayName;
use App\Enums\SubscriptionStatus;
use App\Enums\YearGroup;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'payments.default' => 'paystack', 'payments.grace_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->standard = Plan::factory()->create(['name' => 'Standard membership', 'description' => 'One student', 'amount' => 500000, 'sort_order' => 0, 'paystack_plan_code' => 'PLN_standard']);
    $this->family = Plan::factory()->family(4)->create(['name' => 'Family membership', 'sort_order' => 1, 'paystack_plan_code' => 'PLN_family']);
    Plan::factory()->create(['name' => 'Flutterwave only', 'paystack_plan_code' => null, 'sort_order' => 2]);
    Plan::factory()->create(['name' => 'Retired plan', 'is_active' => false, 'sort_order' => 3]);
    $this->parent = User::factory()->guardian()->create(['name' => 'Ngozi Okafor']);
    $this->ada = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ada Okafor']);
    $this->obi = User::factory()->student(YearGroup::Year7)->create(['name' => 'Obi Okafor']);
    $this->ife = User::factory()->student(YearGroup::Year3)->create(['name' => 'Ife Okafor']);
    foreach ([$this->ada, $this->obi, $this->ife] as $child) {
        $this->parent->linkChild($child, 'Mother');
    }
});

it('shows a parent their children, plans, subscriptions and payments', function () {
    $standing = Subscription::factory()->active()->forStudents($this->ada)->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->standard->id,
        'current_period_ends_at' => CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'),
        'card_brand' => 'Visa', 'card_last4' => '4421',
    ]);
    Subscription::factory()->pending()->forStudents($this->obi)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);
    Payment::factory()->succeeded()->create(['subscription_id' => $standing->id, 'payer_id' => $this->parent->id, 'paid_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC'), 'card_brand' => 'Visa', 'card_last4' => '4421']);
    Payment::factory()->failed('Insufficient funds')->create(['subscription_id' => $standing->id, 'payer_id' => $this->parent->id, 'created_at' => CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC')]);
    Subscription::factory()->active()->create(['plan_id' => $this->standard->id]);

    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/index')
            ->where('available', true)
            ->has('plans', 2)
            ->where('plans.0', ['id' => $this->standard->id, 'name' => 'Standard membership', 'description' => 'One student', 'amount' => '₦5,000', 'maxStudents' => 1])
            ->where('plans.1.maxStudents', 4)
            ->has('children', 3)
            ->where('children.0', ['id' => $this->ada->id, 'name' => 'Ada Okafor', 'yearGroup' => 'Year 5', 'state' => 'covered', 'note' => 'Standard membership · renews 15 Nov'])
            ->where('children.1', ['id' => $this->ife->id, 'name' => 'Ife Okafor', 'yearGroup' => 'Year 3', 'state' => 'none', 'note' => 'No quiz access yet'])
            ->where('children.2.state', 'pending')
            ->where('children.2.note', 'Waiting for the payment to be confirmed')
            ->has('subscriptions', 2)
            ->where('subscriptions.0', [
                'id' => $standing->id,
                'planName' => 'Standard membership',
                'students' => ['Ada Okafor'],
                'status' => 'active',
                'statusLabel' => 'Active',
                'note' => 'Renews on 15 November',
                'card' => 'Visa ···· 4421',
                'gateway' => 'Paystack',
                'canCancel' => true,
                'canUpdateCard' => true,
            ])
            ->where('subscriptions.1.status', 'pending')
            ->where('subscriptions.1.canCancel', false)
            ->has('payments', 2)
            ->where('payments.0', ['id' => Payment::where('status', 'succeeded')->value('id'), 'date' => '15 Oct 2026', 'planName' => 'Standard membership', 'amount' => '₦5,000', 'card' => 'Visa ···· 4421', 'status' => 'succeeded', 'statusLabel' => 'Paid', 'failureReason' => null])
            ->where('payments.1.failureReason', 'Insufficient funds')
            ->has('cancelReasons', 5));
});

it('explains what a past-due or cancelling subscription means', function () {
    Subscription::factory()->pastDue()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);
    Subscription::factory()->active()->forStudents($this->obi)->create([
        'payer_id' => $this->parent->id, 'plan_id' => $this->family->id, 'cancel_at_period_end' => true,
        'current_period_ends_at' => CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
    ]);
    Subscription::factory()->expired()->forStudents($this->ife)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id]);

    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('subscriptions', 2)
            ->where('subscriptions.0.statusLabel', 'Past due')
            ->where('subscriptions.0.note', 'The last payment failed. Update your card to keep access.')
            ->where('subscriptions.1.note', 'Ends on 1 November. It won’t renew.')
            ->where('subscriptions.1.canCancel', false)
            ->where('children.0.state', 'covered')
            ->where('children.1.state', 'none')
            ->where('children.2.state', 'covered'));
});

it('lets a student see and pay only for themselves', function () {
    $this->actingAs($this->ada)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->has('children', 1)->where('children.0.id', $this->ada->id));
});

it('says when payments are not available yet', function () {
    Plan::query()->update(['paystack_plan_code' => null]);

    $this->actingAs($this->parent)->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false)->has('plans', 0));
});

it('is for parents and students only', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('billing.index'))->assertForbidden();
})->with(['tutor', 'admin']);

it('brings the parent back to Billing after paying', function () {
    Http::fake(['*' => Http::response(paymentFixture('paystack', 'verify-success'))]);
    $subscription = Subscription::factory()->pending()->forStudents($this->ada)->create(['payer_id' => $this->parent->id, 'plan_id' => $this->standard->id, 'gateway' => PaymentGatewayName::Paystack]);
    Payment::factory()->create(['subscription_id' => $subscription->id, 'payer_id' => $this->parent->id, 'reference' => 'JGA-01JTESTREFERENCE']);

    $this->actingAs($this->parent)
        ->get(route('billing.callback', ['paystack', 'reference' => 'JGA-01JTESTREFERENCE']))
        ->assertRedirect(route('billing.index'));

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});
