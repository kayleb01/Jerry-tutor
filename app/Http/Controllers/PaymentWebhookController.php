<?php

namespace App\Http\Controllers;

use App\Enums\PaymentGatewayName;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /**
     * Store a signed gateway event once, then apply it on the queue. Answers quickly so the gateway doesn't retry.
     */
    public function __invoke(Request $request, string $gateway, PaymentGatewayManager $gateways): JsonResponse
    {
        $name = PaymentGatewayName::from($gateway);

        try {
            $event = $gateways->gateway($name)->parseWebhook($request);
        } catch (InvalidWebhookSignature) {
            return response()->json(['status' => 'invalid signature'], 401);
        }

        if ($event === null) {
            return response()->json(['status' => 'ignored']);
        }

        $record = WebhookEvent::query()->firstOrCreate(
            ['gateway' => $name, 'event_key' => $event->eventKey],
            ['type' => $event->type->value, 'payload' => json_decode($request->getContent(), true) ?? []],
        );

        // New, or a redelivery of one that failed before: apply it. A redelivery still in flight is left alone.
        if ($record->wasRecentlyCreated || ($record->processed_at === null && $record->error !== null)) {
            ProcessWebhookEvent::dispatch($record, $event);
        }

        return response()->json(['status' => 'ok']);
    }
}
