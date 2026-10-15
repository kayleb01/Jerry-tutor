<?php

namespace Database\Seeders;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Subscriptions and payment history for the demo families, so the admin Subscriptions page has
 * something to show. Only runs where the demo admin exists, and only once.
 */
class DemoSubscriptionSeeder extends Seeder
{
    /** @var array<int, string> */
    private const REASONS = ['Exams finished', 'Exams finished', 'Too expensive', 'Not using it enough'];

    public function run(): void
    {
        $standard = Plan::firstWhere('slug', 'standard');
        $family = Plan::firstWhere('slug', 'family');

        if (! User::where('email', 'admin@academy.test')->exists() || ! $standard || ! $family) {
            return;
        }

        $parents = User::query()
            ->where('email', 'like', 'parent.%@academy.test')
            ->with('children')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $parent): bool => $parent->children->isNotEmpty());

        if ($parents->isEmpty() || Subscription::whereIn('payer_id', $parents->pluck('id'))->exists()) {
            return;
        }

        mt_srand(2027);
        $now = CarbonImmutable::now();
        $cards = [['Visa', '4421'], ['Mastercard', '0907'], ['Visa', '1180'], ['Visa', '3352'], ['Verve', '6610']];

        foreach ($parents->values() as $index => $parent) {
            $children = $parent->children->take($family->max_students);
            $plan = $children->count() > 1 ? $family : $standard;
            [$brand, $last4] = $cards[$index % count($cards)];
            $gateway = $index % 4 === 3 ? PaymentGatewayName::Flutterwave : PaymentGatewayName::Paystack;
            $months = mt_rand(1, 4);
            $renews = $now->addDays(mt_rand(1, 27));

            // Every fifth family is behind on payment; every seventh has cancelled this month.
            $state = match (true) {
                $index % 5 === 2 => 'past_due',
                $index % 7 === 4 => 'cancelling',
                default => 'active',
            };

            $subscription = Subscription::create([
                'payer_id' => $parent->id,
                'plan_id' => $plan->id,
                'gateway' => $gateway,
                'gateway_customer_code' => $gateway === PaymentGatewayName::Paystack ? 'CUS_demo'.$parent->id : (string) (900000 + $parent->id),
                'gateway_subscription_code' => $gateway === PaymentGatewayName::Paystack ? 'SUB_demo'.$parent->id : (string) (4000 + $parent->id),
                'gateway_authorization' => 'AUTH_demo'.$parent->id,
                'card_brand' => $brand,
                'card_last4' => $last4,
                'status' => $state === 'past_due' ? SubscriptionStatus::PastDue : SubscriptionStatus::Active,
                'current_period_ends_at' => $state === 'past_due' ? $now->subDays(mt_rand(2, 6)) : $renews,
                'cancel_at_period_end' => $state === 'cancelling',
                'cancel_requested_at' => $state === 'cancelling' ? $now->startOfMonth()->addDays(mt_rand(0, max(0, $now->day - 2))) : null,
                'cancellation_reason' => $state === 'cancelling' ? self::REASONS[$index % count(self::REASONS)] : null,
            ]);
            $subscription->students()->attach($children->pluck('id'));

            $periodEnd = $subscription->current_period_ends_at;

            for ($month = $months; $month >= 1; $month--) {
                $paidAt = $periodEnd->subMonthsNoOverflow($month)->setTime(9, mt_rand(0, 59));

                if ($paidAt->isFuture()) {
                    continue;
                }

                $this->payment($subscription, PaymentStatus::Succeeded, $paidAt, $gateway, $brand, $last4);
            }

            if ($state === 'past_due') {
                foreach (range(1, mt_rand(1, 3)) as $attempt) {
                    $this->payment($subscription, PaymentStatus::Failed, $periodEnd->addDays($attempt - 1)->setTime(9, 5)->min($now->subMinutes(10)), $gateway, $brand, $last4, ['Card declined', 'Insufficient funds', 'Card expired'][($index + $attempt) % 3]);
                }
            }
        }
    }

    private function payment(Subscription $subscription, PaymentStatus $status, CarbonImmutable $at, PaymentGatewayName $gateway, string $brand, string $last4, ?string $reason = null): void
    {
        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'payer_id' => $subscription->payer_id,
            'gateway' => $gateway,
            'reference' => 'JGA-DEMO-'.Str::ulid(),
            'amount' => $subscription->plan->amount,
            'currency' => 'NGN',
            'status' => $status,
            'failure_reason' => $reason,
            'card_brand' => $brand,
            'card_last4' => $last4,
            'paid_at' => $status === PaymentStatus::Succeeded ? $at : null,
        ]);
        $payment->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}
