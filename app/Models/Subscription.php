<?php

namespace App\Models;

use App\Enums\PaymentGatewayName;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payer's monthly subscription covering one child (Standard) or several (Family).
 *
 * @property int $id
 * @property int $payer_id
 * @property int $plan_id
 * @property PaymentGatewayName $gateway
 * @property string|null $gateway_customer_code
 * @property string|null $gateway_subscription_code
 * @property string|null $gateway_email_token encrypted
 * @property string|null $gateway_authorization encrypted; a reusable card for retries
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $current_period_ends_at
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancel_requested_at when the payer (or gateway) stopped renewals
 * @property CarbonImmutable|null $payment_reminder_sent_at
 */
#[Fillable(['payer_id', 'plan_id', 'gateway', 'billing_email', 'gateway_customer_code', 'gateway_subscription_code', 'gateway_email_token', 'gateway_authorization', 'card_brand', 'card_last4', 'status', 'current_period_ends_at', 'cancel_at_period_end', 'cancelled_at', 'cancellation_reason', 'cancel_requested_at', 'payment_reminder_sent_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentGatewayName::class,
            'status' => SubscriptionStatus::class,
            'gateway_email_token' => 'encrypted',
            'gateway_authorization' => 'encrypted',
            'current_period_ends_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'payment_reminder_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The children this subscription gives quiz access to.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'subscription_student', 'subscription_id', 'student_id')->withTimestamps();
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Pending, active or past due: still holding its children's places.
     *
     * @param  Builder<Subscription>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', SubscriptionStatus::live());
    }

    /**
     * Active and past-due subscriptions run to the end of the paid period plus the grace days;
     * a cancelled one runs to the end of the period it was paid for.
     *
     * @param  Builder<Subscription>  $query
     */
    #[Scope]
    protected function grantingAccess(Builder $query, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $graceDays = (int) config('payments.grace_days');

        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
                ->where('current_period_ends_at', '>', $now->subDays($graceDays)))
            ->orWhere(fn (Builder $query) => $query
                ->where('status', SubscriptionStatus::Cancelled)
                ->where('current_period_ends_at', '>', $now)));
    }

    /**
     * The address the gateway knows the payer by: the receipt email given at checkout, or the account's.
     */
    public function billingEmail(): ?string
    {
        return $this->billing_email ?? $this->payer?->email;
    }
}
