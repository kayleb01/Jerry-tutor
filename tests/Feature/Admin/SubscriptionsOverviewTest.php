<?php

use App\Enums\PaymentGatewayName;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'payments.grace_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
    $this->standard = Plan::factory()->create(['name' => 'Standard membership', 'description' => 'One student', 'amount' => 500000, 'sort_order' => 0]);
    $this->family = Plan::factory()->family(4)->create(['name' => 'Family membership', 'amount' => 1200000, 'sort_order' => 1]);
});

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('admin.subscriptions.index'))->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('summarises subscriptions, revenue, failures and cancellations', function () {
    Subscription::factory()->count(3)->active()->create(['plan_id' => $this->standard->id]);
    Subscription::factory()->active()->create(['plan_id' => $this->family->id]);
    Subscription::factory()->active()->create(['plan_id' => $this->standard->id, 'cancel_at_period_end' => true, 'cancel_requested_at' => now()->subDays(3), 'cancellation_reason' => 'Exams finished']);
    Subscription::factory()->cancelled()->create(['plan_id' => $this->family->id, 'cancel_requested_at' => now()->subDays(5), 'cancellation_reason' => 'Exams finished']);
    Subscription::factory()->cancelled()->create(['plan_id' => $this->standard->id, 'cancel_requested_at' => CarbonImmutable::parse('2026-09-20', 'UTC'), 'cancellation_reason' => 'Too expensive']);
    Subscription::factory()->pastDue()->create(['plan_id' => $this->standard->id]);
    Subscription::factory()->expired()->create(['plan_id' => $this->standard->id]);
    Subscription::factory()->pending()->create(['plan_id' => $this->standard->id]);

    $this->actingAs($this->admin)->get(route('admin.subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/subscriptions/index')
            ->where('stats.active', 6)
            ->where('stats.activeByPlan', '5 Standard · 1 Family')
            ->where('stats.mrr', '₦27,000')
            ->where('stats.failed', 1)
            ->where('stats.graceDays', 7)
            ->where('stats.cancelledThisMonth', 2)
            ->where('stats.topReason', '2 gave “Exams finished”')
            ->where('plans.0', ['id' => $this->standard->id, 'name' => 'Standard membership', 'detail' => 'One child · ₦5,000 / month', 'subscribers' => 5, 'isFamily' => false])
            ->where('plans.1.detail', 'Up to 4 children · ₦12,000 / month')
            ->where('plans.1.isFamily', true));
});

it('lists failed and overdue subscriptions with what went wrong', function () {
    $payer = User::factory()->guardian()->create(['name' => 'Halima Kareem']);
    $subscription = Subscription::factory()->pastDue()->create([
        'payer_id' => $payer->id, 'plan_id' => $this->standard->id, 'gateway_authorization' => 'AUTH_x',
        'payment_reminder_sent_at' => CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'),
    ]);
    Payment::factory()->succeeded()->create(['subscription_id' => $subscription->id, 'created_at' => CarbonImmutable::parse('2026-09-06', 'UTC')]);
    Payment::factory()->failed('Card declined')->create(['subscription_id' => $subscription->id, 'created_at' => CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC')]);
    Payment::factory()->failed('Insufficient funds')->create(['subscription_id' => $subscription->id, 'created_at' => CarbonImmutable::parse('2026-10-09 10:00:00', 'UTC')]);

    $this->actingAs($this->admin)->get(route('admin.subscriptions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attention', 1)
            ->where('attention.0', [
                'id' => $subscription->id,
                'payer' => 'Halima Kareem',
                'plan' => 'Standard',
                'problem' => 'insufficient funds on 9 Oct · attempt 2',
                'attempts' => 2,
                'processing' => false,
                'remindedAt' => '14 Oct',
                'canRetry' => true,
                'canRemind' => true,
            ]));
});

it('shows the latest payments with their method', function () {
    $payer = User::factory()->guardian()->create(['name' => 'Ngozi Okafor']);
    $subscription = Subscription::factory()->active()->create(['payer_id' => $payer->id, 'plan_id' => $this->family->id, 'gateway' => PaymentGatewayName::Paystack]);
    Payment::factory()->succeeded()->create(['subscription_id' => $subscription->id, 'payer_id' => $payer->id, 'amount' => 1200000, 'paid_at' => CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), 'card_brand' => 'Visa', 'card_last4' => '4421']);

    $this->actingAs($this->admin)->get(route('admin.subscriptions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('payments.0.date', '13 Oct 2026')
            ->where('payments.0.payer', 'Ngozi Okafor')
            ->where('payments.0.plan', 'Family')
            ->where('payments.0.amount', '₦12,000')
            ->where('payments.0.method', 'Visa ···· 4421')
            ->where('payments.0.status', 'succeeded'));
});

it('puts a count of failing subscriptions in the admin menu', function () {
    Subscription::factory()->count(2)->pastDue()->create();

    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('badges.subscriptions', 2));
});
