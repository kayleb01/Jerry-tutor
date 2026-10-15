<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the admin Subscriptions page shows: headline numbers, plans, subscriptions needing attention
 * and the latest payments. Money is shown in naira from kobo.
 */
class SubscriptionsOverview
{
    /** A payer gets at most one reminder email in this many hours. */
    public const REMINDER_COOLDOWN_HOURS = 24;

    /** A payment still pending after this long is treated as stuck, and a new retry is allowed. */
    public const IN_FLIGHT_MINUTES = 30;

    /**
     * Whether a recent payment for this subscription hasn't settled yet (a retry or renewal in progress).
     */
    public static function hasPaymentInFlight(Subscription $subscription): bool
    {
        return $subscription->payments()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '>', CarbonImmutable::now()->subMinutes(self::IN_FLIGHT_MINUTES))
            ->exists();
    }

    /**
     * @return array{active: int, activeByPlan: string, mrr: string, failed: int, graceDays: int, cancelledThisMonth: int, topReason: string|null}
     */
    public function stats(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $plans = Plan::query()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $running = Subscription::query()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])->get(['id', 'plan_id', 'status', 'cancel_at_period_end']);

        $mrr = $running
            ->filter(fn (Subscription $subscription): bool => $subscription->status === SubscriptionStatus::Active && ! $subscription->cancel_at_period_end)
            ->sum(fn (Subscription $subscription): int => $plans[$subscription->plan_id]?->amount ?? 0);

        $monthStart = $now->setTimezone(config('academy.timezone'))->startOfMonth()->utc();
        $cancelled = Subscription::query()->where('cancel_requested_at', '>=', $monthStart)->get(['cancellation_reason']);
        // Alphabetical first, then a stable sort by count, so ties always pick the same reason.
        $topReason = $cancelled->pluck('cancellation_reason')->filter()->countBy()->sortKeys()->sortDesc();

        return [
            'active' => $running->count(),
            'activeByPlan' => $running->countBy('plan_id')
                ->sortKeysUsing(fn (int $a, int $b): int => ($plans[$a]?->sort_order <=> $plans[$b]?->sort_order) ?: $a <=> $b)
                ->map(fn (int $count, int $planId): string => $count.' '.self::shortName($plans[$planId]?->name ?? 'Plan'))
                ->join(' · '),
            'mrr' => Plan::naira($mrr),
            'failed' => $running->where('status', SubscriptionStatus::PastDue)->count(),
            'graceDays' => (int) config('payments.grace_days'),
            'cancelledThisMonth' => $cancelled->count(),
            'topReason' => $topReason->isEmpty() ? null : $topReason->first().' gave “'.$topReason->keys()->first().'”',
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, detail: string, subscribers: int, isFamily: bool}>
     */
    public function plans(): Collection
    {
        return Plan::query()
            ->withCount(['subscriptions as running_count' => fn ($query) => $query->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'detail' => ($plan->max_students === 1 ? 'One child' : "Up to {$plan->max_students} children").' · '.$plan->formattedAmount().' / month',
                'subscribers' => (int) $plan->running_count,
                'isFamily' => $plan->max_students > 1,
            ]);
    }

    /**
     * Past-due subscriptions, the longest-failing first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function attention(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $timezone = config('academy.timezone');

        return Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->with(['payer', 'plan', 'payments' => fn ($query) => $query->latest('created_at')->latest('id')])
            ->orderBy('current_period_ends_at')
            ->get()
            ->map(function (Subscription $subscription) use ($now, $timezone): array {
                $recent = $subscription->payments->takeWhile(fn (Payment $payment): bool => $payment->status !== PaymentStatus::Succeeded);
                $lastFailure = $recent->firstWhere('status', PaymentStatus::Failed);
                $attempts = $recent->where('status', PaymentStatus::Failed)->count();

                return [
                    'id' => $subscription->id,
                    'payer' => $subscription->payer->name,
                    'plan' => self::shortName($subscription->plan->name),
                    'problem' => $lastFailure
                        ? Str::lower($lastFailure->failure_reason ?? 'payment failed').' on '.$lastFailure->created_at->setTimezone($timezone)->format('j M').' · attempt '.$attempts
                        : 'renewal overdue since '.$subscription->current_period_ends_at?->setTimezone($timezone)->format('j M'),
                    'attempts' => $attempts,
                    'processing' => $subscription->payments->contains(fn (Payment $payment): bool => $payment->status === PaymentStatus::Pending
                        && $payment->created_at->greaterThan($now->subMinutes(self::IN_FLIGHT_MINUTES))),
                    'remindedAt' => $subscription->payment_reminder_sent_at?->setTimezone($timezone)->format('j M'),
                    'canRetry' => filled($subscription->gateway_authorization),
                    'canRemind' => filled($subscription->payer->email)
                        && ($subscription->payment_reminder_sent_at === null || $subscription->payment_reminder_sent_at->lessThanOrEqualTo($now->subHours(self::REMINDER_COOLDOWN_HOURS))),
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function recentPayments(int $limit = 8): Collection
    {
        return Payment::query()
            ->with(['payer', 'subscription.plan'])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Payment $payment): array => self::paymentRow($payment));
    }

    /**
     * @return array{id: int, date: string, payer: string, payerEmail: string|null, plan: string|null, amount: string, method: string, status: string, statusLabel: string, failureReason: string|null, gateway: string}
     */
    public static function paymentRow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'date' => ($payment->paid_at ?? $payment->created_at)->setTimezone(config('academy.timezone'))->format('j M Y'),
            'payer' => $payment->payer->name,
            'payerEmail' => $payment->payer->email,
            'plan' => $payment->subscription ? self::shortName($payment->subscription->plan->name) : null,
            'amount' => Plan::naira($payment->amount),
            'method' => $payment->card_last4 ? trim(($payment->card_brand ?? 'Card').' ···· '.$payment->card_last4) : $payment->gateway->label(),
            'status' => $payment->status->value,
            'statusLabel' => $payment->status->label(),
            'failureReason' => $payment->status === PaymentStatus::Failed ? $payment->failure_reason : null,
            'gateway' => $payment->gateway->label(),
        ];
    }

    /**
     * "Standard membership" → "Standard".
     */
    public static function shortName(string $planName): string
    {
        return (string) preg_replace('/\s+(membership|plan)$/i', '', $planName);
    }
}
