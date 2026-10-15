<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:expire')]
#[Description('Expire subscriptions past their paid period (plus grace) and close checkouts nobody finished')]
class ExpireSubscriptions extends Command
{
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $graceEnded = $now->subDays((int) config('payments.grace_days'));

        $lapsed = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->where('current_period_ends_at', '<=', $graceEnded)
            ->update(['status' => SubscriptionStatus::Expired]);

        $ended = Subscription::query()
            ->where('status', SubscriptionStatus::Cancelled)
            ->where('current_period_ends_at', '<=', $now)
            ->update(['status' => SubscriptionStatus::Expired]);

        $abandonedIds = Subscription::query()
            ->where('status', SubscriptionStatus::Pending)
            ->where('created_at', '<=', $now->subDay())
            ->pluck('id');
        $abandoned = Subscription::query()->whereKey($abandonedIds)->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $now]);
        Payment::query()->whereIn('subscription_id', $abandonedIds)->where('status', PaymentStatus::Pending)
            ->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'Checkout not completed']);

        $this->info("Expired {$lapsed} lapsed and {$ended} cancelled subscriptions; closed {$abandoned} unfinished checkouts.");

        return self::SUCCESS;
    }
}
