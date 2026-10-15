<?php

namespace App\Models;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge: the first payment of a subscription or a renewal.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property int $payer_id
 * @property PaymentGatewayName $gateway
 * @property string $reference
 * @property string|null $gateway_transaction_id
 * @property int $amount kobo
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $failure_reason
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $gateway_response
 * @property CarbonImmutable $created_at
 */
#[Fillable(['subscription_id', 'payer_id', 'gateway', 'reference', 'gateway_transaction_id', 'amount', 'currency', 'status', 'failure_reason', 'card_brand', 'card_last4', 'paid_at', 'gateway_response'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
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
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'immutable_datetime',
            'gateway_response' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id')->withTrashed();
    }
}
