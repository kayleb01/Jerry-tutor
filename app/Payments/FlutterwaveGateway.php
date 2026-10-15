<?php

namespace App\Payments;

use App\Enums\PaymentGatewayName;
use App\Models\Payment;
use App\Models\Subscription;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Data\GatewayEvent;
use App\Payments\Data\GatewayEventType;
use App\Payments\Data\GatewayPlan;
use App\Payments\Data\VerifiedPayment;
use App\Payments\Exceptions\GatewayException;
use App\Payments\Exceptions\InvalidWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave's v3 API only. Flutterwave works in naira, so amounts are converted at this edge:
 * kobo ÷ 100 on the way out, naira × 100 on the way in.
 */
class FlutterwaveGateway implements PaymentGateway
{
    public function __construct(private string $secretKey, private string $secretHash, private string $baseUrl) {}

    public function name(): PaymentGatewayName
    {
        return PaymentGatewayName::Flutterwave;
    }

    public function fetchPlan(string $code): GatewayPlan
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/payment-plans/'.rawurlencode($code)), allowNotFound: true);

        if ($response->status() === 404 || $response->json('status') !== 'success') {
            throw new GatewayException("Flutterwave doesn't recognise that plan id.");
        }

        $plan = $response->json('data');

        return new GatewayPlan(
            code: (string) $plan['id'],
            amount: self::toKobo($plan['amount']),
            currency: strtoupper((string) $plan['currency']),
            interval: (string) $plan['interval'],
            active: ($plan['status'] ?? null) === 'active',
        );
    }

    public function startCheckout(Subscription $subscription, Payment $payment, string $callbackUrl): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/payments', [
            'tx_ref' => $payment->reference,
            'amount' => self::toNaira($payment->amount),
            'currency' => $payment->currency,
            'redirect_url' => $callbackUrl,
            'payment_plan' => $subscription->plan->flutterwave_plan_id,
            'customer' => ['email' => $subscription->payer->email, 'name' => $subscription->payer->name],
            'meta' => ['subscription_id' => $subscription->id],
            'customizations' => ['title' => 'Jerry Great Academy'],
        ]));

        return (string) ($response->json('data.link') ?? throw new GatewayException('Flutterwave did not return a checkout link.'));
    }

    public function verify(string $reference): VerifiedPayment
    {
        return $this->toVerifiedPayment($this->send(fn (PendingRequest $http) => $http->get('/transactions/verify_by_reference', ['tx_ref' => $reference]))->json('data'), $reference);
    }

    public function chargeSavedCard(Subscription $subscription, Payment $payment): VerifiedPayment
    {
        $data = $this->send(fn (PendingRequest $http) => $http->post('/tokenized-charges', [
            'token' => $subscription->gateway_authorization,
            'email' => $subscription->payer->email,
            'currency' => $payment->currency,
            'amount' => self::toNaira($payment->amount),
            'tx_ref' => $payment->reference,
            'narration' => 'Jerry Great Academy subscription',
        ]))->json('data');

        return $this->toVerifiedPayment($data, $payment->reference, keepReference: true);
    }

    /**
     * @param  mixed  $data  the transaction object Flutterwave returned
     */
    private function toVerifiedPayment(mixed $data, string $reference, bool $keepReference = false): VerifiedPayment
    {
        if (! is_array($data)) {
            throw new GatewayException('Flutterwave returned an unexpected payment response.');
        }

        $succeeded = ($data['status'] ?? null) === 'successful';

        return new VerifiedPayment(
            reference: $keepReference ? $reference : (string) ($data['tx_ref'] ?? $reference),
            succeeded: $succeeded,
            amount: self::toKobo($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            customerCode: isset($data['customer']['id']) ? (string) $data['customer']['id'] : null,
            authorization: $succeeded ? ($data['card']['token'] ?? null) : null,
            cardBrand: $this->cardBrand($data['card'] ?? []),
            cardLast4: $data['card']['last_4digits'] ?? null,
            paidAt: $succeeded && filled($data['created_at'] ?? null) ? CarbonImmutable::parse($data['created_at']) : null,
            failureReason: $succeeded ? null : ($data['processor_response'] ?? 'Payment was not completed'),
            raw: $data,
            pending: ($data['status'] ?? null) === 'pending',
        );
    }

    public function cancel(Subscription $subscription): void
    {
        if (blank($subscription->gateway_subscription_code)) {
            $id = $this->findSubscriptionId($subscription->payer->email, (string) $subscription->plan->flutterwave_plan_id)
                ?? throw new GatewayException("Flutterwave hasn't confirmed this subscription yet. Please try again in a few minutes.");
            $subscription->update(['gateway_subscription_code' => $id]);
        }

        $this->send(fn (PendingRequest $http) => $http->put('/subscriptions/'.rawurlencode((string) $subscription->gateway_subscription_code).'/cancel'));
    }

    public function parseWebhook(Request $request): ?GatewayEvent
    {
        $hash = (string) $request->header('verif-hash');

        if ($this->secretHash === '' || $hash === '' || ! hash_equals($this->secretHash, $hash)) {
            throw new InvalidWebhookSignature('The Flutterwave verif-hash did not match.');
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $data = $payload['data'] ?? [];

        if (($payload['event'] ?? null) !== 'charge.completed' || ! isset($data['id'])) {
            return null;
        }

        $succeeded = ($data['status'] ?? null) === 'successful';

        return new GatewayEvent(
            type: $succeeded ? GatewayEventType::PaymentSucceeded : GatewayEventType::PaymentFailed,
            eventKey: $data['id'].':'.($data['status'] ?? 'unknown'),
            reference: $data['tx_ref'] ?? null,
            customerCode: isset($data['customer']['id']) ? (string) $data['customer']['id'] : null,
            amount: self::toKobo($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            transactionId: (string) $data['id'],
            authorization: $succeeded ? ($data['card']['token'] ?? null) : null,
            cardBrand: $this->cardBrand($data['card'] ?? []),
            cardLast4: $data['card']['last_4digits'] ?? null,
            paidAt: $succeeded && filled($data['created_at'] ?? null) ? CarbonImmutable::parse($data['created_at']) : null,
            failureReason: $succeeded ? null : ($data['processor_response'] ?? 'Payment failed'),
            raw: $payload,
        );
    }

    /**
     * Flutterwave's verif-hash is a shared secret rather than a signature of the body, so a successful
     * charge is re-fetched by transaction id (as Flutterwave advises) and the verified figures are used.
     */
    public function confirm(GatewayEvent $event): GatewayEvent
    {
        if ($event->type !== GatewayEventType::PaymentSucceeded) {
            return $event;
        }

        $data = $this->send(fn (PendingRequest $http) => $http->get('/transactions/'.rawurlencode((string) $event->transactionId).'/verify'))->json('data');

        if (! is_array($data) || (string) ($data['id'] ?? '') !== (string) $event->transactionId) {
            throw new GatewayException('Flutterwave could not confirm that transaction.');
        }

        $succeeded = ($data['status'] ?? null) === 'successful';

        return $event->with([
            'type' => $succeeded ? GatewayEventType::PaymentSucceeded : GatewayEventType::PaymentFailed,
            'reference' => $data['tx_ref'] ?? $event->reference,
            'amount' => self::toKobo($data['amount'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'authorization' => $succeeded ? ($data['card']['token'] ?? $event->authorization) : null,
            'failureReason' => $succeeded ? null : ($data['processor_response'] ?? 'Payment failed'),
        ]);
    }

    /**
     * Flutterwave creates the subscription itself after the first plan payment; this finds its id.
     */
    public function findSubscriptionId(string $email, string $planId): ?string
    {
        $subscriptions = $this->send(fn (PendingRequest $http) => $http->get('/subscriptions', ['email' => $email]))->json('data') ?? [];

        $match = collect($subscriptions)
            ->filter(fn (array $subscription): bool => (string) ($subscription['plan'] ?? '') === $planId && ($subscription['status'] ?? null) === 'active')
            ->sortByDesc('created_at')
            ->first();

        return $match ? (string) $match['id'] : null;
    }

    public static function toNaira(int $kobo): int|float
    {
        return $kobo % 100 === 0 ? intdiv($kobo, 100) : $kobo / 100;
    }

    public static function toKobo(int|float|string $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function cardBrand(array $card): ?string
    {
        $type = trim((string) ($card['type'] ?? ''));

        return $type === '' ? null : ucfirst(strtolower($type));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $allowNotFound = false): Response
    {
        try {
            $response = $call(Http::baseUrl($this->baseUrl)->withToken($this->secretKey)->acceptJson()->asJson()->timeout(20));
        } catch (ConnectionException) {
            throw new GatewayException("Flutterwave couldn't be reached. Please try again.");
        }

        if ($response->failed() && ! ($allowNotFound && $response->status() === 404)) {
            throw new GatewayException('Flutterwave said: '.($response->json('message') ?? 'the request failed').'.');
        }

        return $response;
    }
}
