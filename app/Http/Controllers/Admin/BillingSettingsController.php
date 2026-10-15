<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentGatewayName;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Plan;
use App\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    /**
     * Plans and their links to the Paystack and Flutterwave dashboards.
     */
    public function index(PaymentGatewayManager $gateways): Response
    {
        $plans = Plan::query()
            ->withCount(['subscriptions as live_subscriptions_count' => fn ($query) => $query->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/billing/index', [
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'amount' => $plan->formattedAmount(),
                'maxStudents' => $plan->max_students,
                'paystackPlanCode' => $plan->paystack_plan_code,
                'flutterwavePlanId' => $plan->flutterwave_plan_id,
                'isActive' => $plan->is_active,
                'subscribers' => $plan->live_subscriptions_count,
            ]),
            'gateways' => collect(PaymentGatewayName::cases())->map(fn (PaymentGatewayName $gateway): array => [
                'value' => $gateway->value,
                'label' => $gateway->label(),
                'isDefault' => $gateways->defaultGateway() === $gateway,
                'linkedPlans' => $plans->filter(fn (Plan $plan): bool => $plan->is_active && $plan->isLinkedTo($gateway))->count(),
                'hasKeys' => filled(config("payments.{$gateway->value}.secret_key")),
            ]),
        ]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$plan->name} saved."]);

        return back();
    }
}
