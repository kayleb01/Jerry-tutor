<?php

namespace App\Jobs;

use App\Actions\ApplyGatewayEvent;
use App\Models\WebhookEvent;
use App\Payments\Data\GatewayEvent;
use App\Payments\PaymentGatewayManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies a stored webhook. Retries with back-off, because events can arrive out of order
 * (e.g. Paystack's subscription.create before the charge that it belongs to).
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public WebhookEvent $record, public GatewayEvent $event) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(ApplyGatewayEvent $applyGatewayEvent, PaymentGatewayManager $gateways): void
    {
        try {
            $event = $gateways->gateway($this->record->gateway)->confirm($this->event);
            $applyGatewayEvent->handle($this->record->gateway, $event);
        } catch (Throwable $exception) {
            $this->record->update(['error' => $exception->getMessage()]);

            throw $exception;
        }

        $this->record->update(['processed_at' => now(), 'error' => null]);
    }
}
