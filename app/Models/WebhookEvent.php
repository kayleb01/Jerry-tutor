<?php

namespace App\Models;

use App\Enums\PaymentGatewayName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A gateway webhook as received. Unique per gateway and event key, so a repeat delivery is ignored.
 *
 * @property int $id
 * @property PaymentGatewayName $gateway
 * @property string $event_key
 * @property string $type
 * @property array<string, mixed> $payload the raw request body, decoded
 * @property CarbonImmutable|null $processed_at
 * @property string|null $error
 */
#[Fillable(['gateway', 'event_key', 'type', 'payload', 'processed_at', 'error'])]
class WebhookEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentGatewayName::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
