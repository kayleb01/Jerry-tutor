<?php

namespace App\Actions;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Exceptions\CheckoutUnavailable;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Opens a subscription for one or more children and hands back the gateway's hosted checkout URL.
 * Nothing grants access here: the subscription stays Pending until a payment is verified.
 */
class StartCheckout
{
    public function __construct(private PaymentGatewayManager $gateways) {}

    /**
     * @param  Collection<int, User>  $students
     *
     * @throws CheckoutUnavailable when the plan can't be sold through the gateway
     * @throws ValidationException when the selection isn't allowed
     * @throws GatewayException when the gateway refuses (the payment is recorded as failed)
     */
    public function handle(User $payer, Plan $plan, Collection $students, ?PaymentGatewayName $gateway = null): string
    {
        $gateway ??= $this->gateways->defaultGateway();

        if (! $plan->is_active || ! $plan->isLinkedTo($gateway)) {
            throw new CheckoutUnavailable('Payments are unavailable right now. Please try again later.');
        }

        $this->ensureCanCover($payer, $plan, $students);

        [$subscription, $payment] = DB::transaction(function () use ($payer, $plan, $students, $gateway): array {
            $studentIds = $students->pluck('id')->all();

            // An earlier checkout for any of these children that was never paid gives way to this one.
            $superseded = Subscription::query()
                ->where('status', SubscriptionStatus::Pending)
                ->whereHas('students', fn ($query) => $query->whereIn('users.id', $studentIds))
                ->pluck('id');
            Subscription::query()->whereKey($superseded)->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
            Payment::query()->whereIn('subscription_id', $superseded)->where('status', PaymentStatus::Pending)
                ->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'Checkout not completed']);

            $subscription = Subscription::create([
                'payer_id' => $payer->id,
                'plan_id' => $plan->id,
                'gateway' => $gateway,
                'status' => SubscriptionStatus::Pending,
            ]);
            $subscription->students()->attach($studentIds);

            $payment = Payment::create([
                'subscription_id' => $subscription->id,
                'payer_id' => $payer->id,
                'gateway' => $gateway,
                'reference' => 'JGA-'.Str::ulid(),
                'amount' => $plan->amount,
                'currency' => $plan->currency,
                'status' => PaymentStatus::Pending,
            ]);

            return [$subscription, $payment];
        });

        try {
            return $this->gateways->gateway($gateway)->startCheckout(
                $subscription->load(['plan', 'payer']),
                $payment,
                route('billing.callback', $gateway->value),
            );
        } catch (GatewayException $exception) {
            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => $exception->getMessage()]);
            $subscription->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

            throw $exception;
        }
    }

    /**
     * @param  Collection<int, User>  $students
     *
     * @throws ValidationException
     */
    private function ensureCanCover(User $payer, Plan $plan, Collection $students): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['students' => $message]);

        if ($students->isEmpty()) {
            $fail('Choose at least one child.');
        }

        if ($students->count() > $plan->max_students) {
            $fail($plan->max_students === 1
                ? "The {$plan->name} covers one child."
                : "The {$plan->name} covers up to {$plan->max_students} children.");
        }

        if ($payer->hasRole(Role::Student)) {
            if ($students->contains(fn (User $student): bool => ! $student->is($payer))) {
                $fail('You can only subscribe for yourself.');
            }
        } else {
            $ownChildren = $payer->children()->pluck('users.id');

            if ($students->contains(fn (User $student): bool => ! $ownChildren->contains($student->id))) {
                $fail('You can only subscribe for your own children.');
            }
        }

        $samePlan = $payer->subscriptions()
            ->where('plan_id', $plan->id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->exists();

        if ($samePlan) {
            $bigger = Plan::query()->active()->where('max_students', '>', $plan->max_students)->orderBy('max_students')->value('name');
            $fail("You already have a {$plan->name}.".($bigger ? " Choose the {$bigger} to cover more children." : ''));
        }

        $covered = $students->first(fn (User $student): bool => $student->coveringSubscriptions()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->exists());

        if ($covered) {
            $fail("{$covered->name} already has a subscription.");
        }
    }
}
