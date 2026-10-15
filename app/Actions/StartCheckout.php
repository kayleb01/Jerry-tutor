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
    /** How long someone else's unfinished checkout for a child holds off a new one. */
    public const OTHER_CHECKOUT_MINUTES = 30;

    public function __construct(private PaymentGatewayManager $gateways) {}

    /**
     * @param  Collection<int, User>  $students
     * @param  string|null  $billingEmail  where receipts go when the payer's account has no email
     *
     * @throws CheckoutUnavailable when the plan can't be sold through the gateway
     * @throws ValidationException when the selection isn't allowed
     * @throws GatewayException when the gateway refuses (the payment is recorded as failed)
     */
    public function handle(User $payer, Plan $plan, Collection $students, ?PaymentGatewayName $gateway = null, ?string $billingEmail = null): string
    {
        $gateway ??= $this->gateways->defaultGateway();

        if (! $plan->is_active || ! $plan->isLinkedTo($gateway)) {
            throw new CheckoutUnavailable('Payments are unavailable right now. Please try again later.');
        }

        // The gateway knows the payer by this address, so it is fixed on the subscription now.
        $email = Str::lower(trim((string) ($billingEmail ?? $payer->email)));

        $this->ensureCanCover($payer, $plan, $students, $email, emailField: $payer->email === null ? 'email' : 'students');

        [$subscription, $payment] = DB::transaction(function () use ($payer, $plan, $students, $gateway, $email): array {
            $studentIds = $students->pluck('id')->all();

            // This payer's earlier checkout for any of these children that was never paid gives way to this one.
            $superseded = Subscription::query()
                ->where('payer_id', $payer->id)
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
                'billing_email' => $email,
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
    private function ensureCanCover(User $payer, Plan $plan, Collection $students, string $email, string $emailField): void
    {
        $fail = fn (string $message, string $field = 'students') => throw ValidationException::withMessages([$field => $message]);

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

            if ($plan->max_students !== 1) {
                $fail("The {$plan->name} is for parents. Choose a plan for one student.");
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

        // Someone else is part-way through paying for one of these children: don't cancel their checkout.
        $othersCheckout = Subscription::query()
            ->with('payer')
            ->where('payer_id', '!=', $payer->id)
            ->where('status', SubscriptionStatus::Pending)
            ->where('created_at', '>', now()->subMinutes(self::OTHER_CHECKOUT_MINUTES))
            ->whereHas('students', fn ($query) => $query->whereKey($students->pluck('id')))
            ->latest('id')
            ->first();

        if ($othersCheckout) {
            $child = $othersCheckout->students()->whereKey($students->pluck('id'))->orderBy('name')->first();
            $who = $child?->is($payer) ? 'you' : Str::before((string) $child?->name, ' ');
            $fail(Str::before($othersCheckout->payer->name, ' ')." is paying for {$who} right now. If that payment doesn’t go through, try again in ".self::OTHER_CHECKOUT_MINUTES.' minutes.');
        }

        // Gateways know customers by email, so two payers sharing one would get their subscriptions mixed up.
        $emailTaken = Subscription::query()
            ->where('payer_id', '!=', $payer->id)
            ->where('billing_email', $email)
            ->where(fn ($query) => $query
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
                ->orWhere(fn ($query) => $query->where('status', SubscriptionStatus::Cancelled)->where('current_period_ends_at', '>', now()))
                ->orWhere(fn ($query) => $query->where('status', SubscriptionStatus::Pending)->where('created_at', '>', now()->subDay())))
            ->exists();

        if ($emailTaken) {
            $fail($emailField === 'email'
                ? 'This email already pays for a subscription on another account. Use a different email for your receipts.'
                : 'Your email already pays for a subscription on another account, so the payment provider can’t tell them apart. Please contact the academy.', $emailField);
        }
    }
}
