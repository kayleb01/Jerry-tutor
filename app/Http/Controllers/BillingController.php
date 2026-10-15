<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /** @var array<int, string> */
    public const CANCEL_REASONS = ['Exams finished', 'Too expensive', 'Not using it enough', 'Moving to another tutor', 'Something else'];

    /**
     * The payer's view: each child's quiz access, plans to subscribe to, their subscriptions and payments.
     */
    public function index(Request $request, PaymentGatewayManager $gateways): Response
    {
        $payer = $request->user();
        $now = CarbonImmutable::now();
        $gateway = $gateways->defaultGateway();

        $isStudent = $payer->hasRole(Role::Student);

        // A student can only pay for themselves, so family plans aren't offered to them.
        $plans = Plan::query()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Plan $plan): bool => $plan->isLinkedTo($gateway) && (! $isStudent || $plan->max_students === 1))
            ->values();

        $children = $isStudent
            ? collect([$payer->load('studentProfile')])
            : $payer->children()->with('studentProfile')->orderBy('name')->get();

        $subscriptions = $payer->subscriptions()
            ->with(['plan', 'students'])
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
                ->orWhere(fn (Builder $query) => $query->where('status', SubscriptionStatus::Cancelled)->where('current_period_ends_at', '>', $now))
                ->orWhere(fn (Builder $query) => $query->where('status', SubscriptionStatus::Pending)->where('created_at', '>', $now->subDay())))
            ->get()
            ->sortBy(fn (Subscription $subscription): string => array_search($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Active, SubscriptionStatus::Cancelled, SubscriptionStatus::Pending], true).'-'.str_pad((string) $subscription->id, 10, '0', STR_PAD_LEFT))
            ->values();

        return Inertia::render('billing/index', [
            'available' => $plans->isNotEmpty(),
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'amount' => $plan->formattedAmount(),
                'maxStudents' => $plan->max_students,
            ]),
            'payer' => [
                'isStudent' => $isStudent,
                'needsEmail' => $payer->email === null,
            ],
            'children' => $children->map(fn (User $child): array => $this->child($child, $payer, $now)),
            'subscriptions' => $subscriptions->map(fn (Subscription $subscription): array => $this->subscription($subscription)),
            'payments' => Payment::query()
                ->where('payer_id', $payer->id)
                ->with('subscription.plan')
                ->latest('created_at')
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'date' => ($payment->paid_at ?? $payment->created_at)->setTimezone(config('academy.timezone'))->format('j M Y'),
                    'planName' => $payment->subscription?->plan->name,
                    'amount' => Plan::naira($payment->amount),
                    'card' => $this->card($payment->card_brand, $payment->card_last4),
                    'status' => $payment->status->value,
                    'statusLabel' => $payment->status->label(),
                    'failureReason' => $payment->status === PaymentStatus::Failed ? $payment->failure_reason : null,
                ]),
            'cancelReasons' => self::CANCEL_REASONS,
        ]);
    }

    /**
     * @return array{id: int, name: string, yearGroup: string|null, state: string, note: string, pendingOther: bool}
     */
    private function child(User $child, User $viewer, CarbonImmutable $now): array
    {
        $covering = $child->coveringSubscriptions()->grantingAccess($now)->with(['plan', 'payer'])->latest('current_period_ends_at')->first();
        $pending = $covering === null ? $child->coveringSubscriptions()
            ->with('payer')
            ->where('status', SubscriptionStatus::Pending)
            ->where('subscriptions.created_at', '>', $now->subDay())
            ->latest('subscriptions.id')
            ->first() : null;
        $pendingOther = $pending !== null && $pending->payer_id !== $viewer->id;

        [$state, $note] = match (true) {
            $covering !== null => ['covered', $covering->plan->name.' · '.$this->shortStatus($covering).$this->paidBy($covering, $viewer)],
            $pendingOther => ['pending', Str::before($pending->payer->name, ' ').' started paying'.($child->is($viewer) ? ' for you' : '')],
            $pending !== null => ['pending', 'Waiting for the payment to be confirmed'],
            default => ['none', 'No quiz access yet'],
        };

        return [
            'id' => $child->id,
            'name' => $child->name,
            'yearGroup' => $child->studentProfile?->year_group->label(),
            'state' => $state,
            'note' => $note,
            'pendingOther' => $pendingOther,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(Subscription $subscription): array
    {
        $running = in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true);
        $endsOn = $subscription->current_period_ends_at?->setTimezone(config('academy.timezone'))->format('j F');

        return [
            'id' => $subscription->id,
            'planName' => $subscription->plan->name,
            'students' => $subscription->students->sortBy('name')->pluck('name')->values()->all(),
            'status' => $subscription->status->value,
            'statusLabel' => $subscription->status->label(),
            'note' => match (true) {
                $subscription->status === SubscriptionStatus::Pending => 'Waiting for the payment to be confirmed.',
                $subscription->status === SubscriptionStatus::PastDue => 'The last payment failed. Update your card to keep access.',
                $subscription->status === SubscriptionStatus::Cancelled, $subscription->cancel_at_period_end => "Ends on {$endsOn}. It won’t renew.",
                default => "Renews on {$endsOn}",
            },
            'card' => $this->card($subscription->card_brand, $subscription->card_last4),
            'gateway' => $subscription->gateway->label(),
            'canCancel' => $running && ! $subscription->cancel_at_period_end,
            'canUpdateCard' => $running && ! $subscription->cancel_at_period_end,
        ];
    }

    /**
     * A child can be paid for by a parent or pay for themselves; say so when it isn't the viewer.
     */
    private function paidBy(Subscription $subscription, User $viewer): string
    {
        return $subscription->payer_id === $viewer->id ? '' : ' · paid by '.Str::before($subscription->payer->name, ' ');
    }

    private function shortStatus(Subscription $subscription): string
    {
        $date = $subscription->current_period_ends_at?->setTimezone(config('academy.timezone'))->format('j M');

        return match (true) {
            $subscription->status === SubscriptionStatus::PastDue => 'payment failed',
            $subscription->status === SubscriptionStatus::Cancelled, $subscription->cancel_at_period_end => "ends {$date}",
            default => "renews {$date}",
        };
    }

    private function card(?string $brand, ?string $last4): ?string
    {
        return $last4 ? trim(($brand ?? 'Card').' ···· '.$last4) : null;
    }
}
