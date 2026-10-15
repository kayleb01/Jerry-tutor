<?php

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['payments.default' => 'paystack', 'payments.paystack.secret_key' => 'sk_test', 'payments.flutterwave.secret_key' => 'FLW_test']);
    $this->admin = User::factory()->admin()->create();
    $this->standard = Plan::factory()->unlinked()->create(['name' => 'Standard membership', 'slug' => 'standard', 'amount' => 500000, 'max_students' => 1]);
    $this->family = Plan::factory()->unlinked()->family(4)->create(['slug' => 'family']);
});

function planPayload(Plan $plan, array $overrides = []): array
{
    return [
        'name' => $plan->name,
        'description' => $plan->description,
        'max_students' => $plan->max_students,
        'paystack_plan_code' => $plan->paystack_plan_code,
        'flutterwave_plan_id' => $plan->flutterwave_plan_id,
        'is_active' => $plan->is_active,
        ...$overrides,
    ];
}

function paystackPlan(array $overrides = []): array
{
    $plan = paymentFixture('paystack', 'plan');
    $plan['data'] = [...$plan['data'], ...$overrides];

    return $plan;
}

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('admin.billing.index'))->assertForbidden();
    $this->actingAs(User::factory()->{$state}()->create())->put(route('admin.billing.plans.update', $this->standard), planPayload($this->standard))->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists the plans with their links and which gateway is ready', function () {
    $this->standard->update(['paystack_plan_code' => 'PLN_standard']);

    $this->actingAs($this->admin)->get(route('admin.billing.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/billing/index')
            ->has('plans', 2)
            ->where('plans.0.name', 'Standard membership')
            ->where('plans.0.amount', '₦5,000')
            ->where('plans.0.paystackPlanCode', 'PLN_standard')
            ->where('plans.1.maxStudents', 4)
            ->where('gateways.0', ['value' => 'paystack', 'label' => 'Paystack', 'isDefault' => true, 'linkedPlans' => 1, 'hasKeys' => true])
            ->where('gateways.1.linkedPlans', 0));
});

it('links a plan after checking it against the gateway', function () {
    Http::fake([
        'api.paystack.co/plan/PLN_standard' => Http::response(paystackPlan()),
        'api.flutterwave.com/v3/payment-plans/61427' => Http::response(paymentFixture('flutterwave', 'plan')),
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.plans.update', $this->standard), planPayload($this->standard, ['paystack_plan_code' => ' PLN_standard ', 'flutterwave_plan_id' => '61427']))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($this->standard->fresh())
        ->paystack_plan_code->toBe('PLN_standard')
        ->flutterwave_plan_id->toBe('61427');
});

it('refuses a plan that does not match', function (array $overrides, string $message) {
    Http::fake(['api.paystack.co/plan/*' => Http::response(paystackPlan($overrides))]);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.plans.update', $this->standard), planPayload($this->standard, ['paystack_plan_code' => 'PLN_standard']))
        ->assertSessionHasErrors(['paystack_plan_code' => $message]);

    expect($this->standard->fresh()->paystack_plan_code)->toBeNull();
})->with([
    'wrong amount' => [['amount' => 600000], 'That plan is ₦6,000 a month in Paystack, but this plan is ₦5,000.'],
    'not naira' => [['currency' => 'USD'], "That plan isn't in naira."],
    'not monthly' => [['interval' => 'annually'], "That plan doesn't renew monthly."],
    'archived' => [['is_archived' => true], 'That plan is archived in Paystack.'],
]);

it('says when the gateway does not know the code', function () {
    Http::fake(['api.paystack.co/plan/*' => Http::response(['status' => false, 'message' => 'Plan not found'], 404)]);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.plans.update', $this->standard), planPayload($this->standard, ['paystack_plan_code' => 'PLN_missing']))
        ->assertSessionHasErrors(['paystack_plan_code' => "Paystack doesn't recognise that plan code."]);
});

it('does not re-check a code that has not changed, and edits names and family size', function () {
    $this->family->update(['paystack_plan_code' => 'PLN_family']);
    Http::fake();

    $this->actingAs($this->admin)
        ->put(route('admin.billing.plans.update', $this->family), planPayload($this->family->fresh(), ['name' => 'Family plan', 'max_students' => 5]))
        ->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect($this->family->fresh())->name->toBe('Family plan')->max_students->toBe(5);
});

it('keeps the Standard plan to one child and family sizes sensible', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.billing.plans.update', $this->standard), planPayload($this->standard, ['max_students' => 2]))
        ->assertSessionHasErrors(['max_students' => 'The Standard plan covers one child.']);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.plans.update', $this->family), planPayload($this->family, ['max_students' => 11]))
        ->assertSessionHasErrors(['max_students']);
});
