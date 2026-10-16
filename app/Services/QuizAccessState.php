<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Whether a student has quiz access, as the person looking at it should read it: covered (perhaps with a failed renewal), waiting for a payment, or not covered.
 */
class QuizAccessState
{
    /**
     * @return array{state: 'covered'|'pending'|'none', note: string, pendingOther: bool, paymentFailed: bool}
     */
    public function for(User $student, User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $covering = $student->coveringSubscriptions()->grantingAccess($now)->with(['plan', 'payer'])->latest('current_period_ends_at')->first();
        $pending = $covering === null ? $student->coveringSubscriptions()
            ->with('payer')
            ->where('status', SubscriptionStatus::Pending)
            ->where('subscriptions.created_at', '>', $now->subDay())
            ->latest('subscriptions.id')
            ->first() : null;
        $pendingOther = $pending !== null && $pending->payer_id !== $viewer->id;

        [$state, $note] = match (true) {
            $covering !== null => ['covered', $covering->plan->name.' · '.$this->shortStatus($covering).$this->paidBy($covering, $viewer)],
            $pendingOther => ['pending', Str::before($pending->payer->name, ' ').' started paying'.($student->is($viewer) ? ' for you' : '')],
            $pending !== null => ['pending', 'Waiting for the payment to be confirmed'],
            default => ['none', 'No quiz access yet'],
        };

        return [
            'state' => $state,
            'note' => $note,
            'pendingOther' => $pendingOther,
            // Still covered during the grace period, but the payer needs to update their card.
            'paymentFailed' => $covering?->status === SubscriptionStatus::PastDue,
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
}
