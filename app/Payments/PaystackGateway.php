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
 * Paystack's REST API (single stable version). Amounts are already kobo, so nothing is converted.
 */
class PaystackGateway implements PaymentGateway
{
    public function __construct(private string $secretKey, private string $baseUrl) {}

    public function name(): PaymentGatewayName
    {
        return PaymentGatewayName::Paystack;
    }

    public function fetchPlan(string $code): GatewayPlan
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/plan/'.rawurlencode($code)), allowNotFound: true);

        if ($response->status() === 404 || ! $response->json('status')) {
            throw new GatewayException("Paystack doesn't recognise that plan code.");
        }

        $plan = $response->json('data');

        return new GatewayPlan(
            code: (string) $plan['plan_code'],
            amount: (int) $plan['amount'],
            currency: strtoupper((string) $plan['currency']),
            interval: (string) $plan['interval'],
            active: ! ($plan['is_archived'] ?? false) && ! ($plan['is_deleted'] ?? false),
        );
    }

    public function startCheckout(Subscription $subscription, Payment $payment, string $callbackUrl): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transaction/initialize', [
            'email' => $subscription->payer->email,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'plan' => $subscription->plan->paystack_plan_code,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl,
            'metadata' => ['subscription_id' => $subscription->id],
        ]));

        return (string) ($response->json('data.authorization_url') ?? throw new GatewayException('Paystack did not return a checkout link.'));
    }

    public function verify(string $reference): VerifiedPayment
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)))->json('data');

        if (! is_array($data)) {
            throw new GatewayException('Paystack returned an unexpected verification response.');
        }

        $succeeded = ($data['status'] ?? null) === 'success';

        return new VerifiedPayment(
            reference: (string) ($data['reference'] ?? $reference),
            succeeded: $succeeded,
            amount: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            customerCode: $data['customer']['customer_code'] ?? null,
            authorization: $succeeded && ($data['authorization']['reusable'] ?? false) ? $data['authorization']['authorization_code'] : null,
            cardBrand: $this->cardBrand($data['authorization'] ?? []),
            cardLast4: $data['authorization']['last4'] ?? null,
            paidAt: filled($data['paid_at'] ?? null) ? CarbonImmutable::parse($data['paid_at']) : null,
            failureReason: $succeeded ? null : ($data['gateway_response'] ?? 'Payment was not completed'),
            raw: $data,
        );
    }

    public function cancel(Subscription $subscription): void
    {
        $code = $subscription->gateway_subscription_code
            ?? throw new GatewayException("Paystack hasn't confirmed this subscription yet. Please try again in a few minutes.");

        // The email token normally arrives with subscription.create; fetch it if that webhook hasn't landed.
        if (blank($subscription->gateway_email_token)) {
            $token = $this->send(fn (PendingRequest $http) => $http->get('/subscription/'.rawurlencode($code)))->json('data.email_token');
            $subscription->update(['gateway_email_token' => $token ?? throw new GatewayException('Paystack did not return the subscription token.')]);
        }

        $this->send(fn (PendingRequest $http) => $http->post('/subscription/disable', [
            'code' => $code,
            'token' => $subscription->gateway_email_token,
        ]));
    }

    public function parseWebhook(Request $request): ?GatewayEvent
    {
        $body = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature');

        if ($signature === '' || ! hash_equals(hash_hmac('sha512', $body, $this->secretKey), $signature)) {
            throw new InvalidWebhookSignature('The Paystack signature did not match.');
        }

        $payload = json_decode($body, true) ?: [];
        $data = $payload['data'] ?? [];
        $key = hash('sha256', $body);

        return match ($payload['event'] ?? null) {
            // Renewal charges carry Paystack's own reference; invoice.update reports those.
            'charge.success' => str_starts_with((string) ($data['reference'] ?? ''), 'JGA-') ? new GatewayEvent(
                type: GatewayEventType::PaymentSucceeded,
                eventKey: $key,
                reference: $data['reference'],
                customerCode: $data['customer']['customer_code'] ?? null,
                amount: (int) $data['amount'],
                currency: strtoupper((string) $data['currency']),
                transactionId: isset($data['id']) ? (string) $data['id'] : null,
                authorization: ($data['authorization']['reusable'] ?? false) ? $data['authorization']['authorization_code'] : null,
                cardBrand: $this->cardBrand($data['authorization'] ?? []),
                cardLast4: $data['authorization']['last4'] ?? null,
                paidAt: filled($data['paid_at'] ?? null) ? CarbonImmutable::parse($data['paid_at']) : null,
                raw: $payload,
            ) : null,
            'subscription.create' => new GatewayEvent(
                type: GatewayEventType::SubscriptionCreated,
                eventKey: $key,
                subscriptionCode: $data['subscription_code'] ?? null,
                customerCode: $data['customer']['customer_code'] ?? null,
                emailToken: $data['email_token'] ?? null,
                periodEndsAt: filled($data['next_payment_date'] ?? null) ? CarbonImmutable::parse($data['next_payment_date']) : null,
                raw: $payload,
            ),
            'invoice.update' => ($data['paid'] ?? false) ? new GatewayEvent(
                type: GatewayEventType::PaymentSucceeded,
                eventKey: $key,
                reference: $data['transaction']['reference'] ?? null,
                subscriptionCode: $data['subscription']['subscription_code'] ?? null,
                customerCode: $data['customer']['customer_code'] ?? null,
                amount: (int) ($data['transaction']['amount'] ?? $data['amount'] ?? 0),
                currency: strtoupper((string) ($data['transaction']['currency'] ?? 'NGN')),
                periodEndsAt: filled($data['subscription']['next_payment_date'] ?? null) ? CarbonImmutable::parse($data['subscription']['next_payment_date']) : null,
                authorization: ($data['authorization']['reusable'] ?? false) ? $data['authorization']['authorization_code'] : null,
                cardBrand: $this->cardBrand($data['authorization'] ?? []),
                cardLast4: $data['authorization']['last4'] ?? null,
                paidAt: filled($data['paid_at'] ?? null) ? CarbonImmutable::parse($data['paid_at']) : null,
                raw: $payload,
            ) : null,
            'invoice.payment_failed' => new GatewayEvent(
                type: GatewayEventType::PaymentFailed,
                eventKey: $key,
                reference: $data['transaction']['reference'] ?? null,
                subscriptionCode: $data['subscription']['subscription_code'] ?? null,
                customerCode: $data['customer']['customer_code'] ?? null,
                amount: (int) ($data['transaction']['amount'] ?? $data['amount'] ?? 0),
                currency: strtoupper((string) ($data['transaction']['currency'] ?? 'NGN')),
                failureReason: $data['transaction']['gateway_response'] ?? $data['description'] ?? 'Payment failed',
                raw: $payload,
            ),
            'subscription.not_renew' => new GatewayEvent(
                type: GatewayEventType::SubscriptionNotRenewing,
                eventKey: $key,
                subscriptionCode: $data['subscription_code'] ?? null,
                customerCode: $data['customer']['customer_code'] ?? null,
                raw: $payload,
            ),
            'subscription.disable' => new GatewayEvent(
                type: GatewayEventType::SubscriptionCancelled,
                eventKey: $key,
                subscriptionCode: $data['subscription_code'] ?? null,
                customerCode: $data['customer']['customer_code'] ?? null,
                raw: $payload,
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $authorization
     */
    private function cardBrand(array $authorization): ?string
    {
        $type = trim((string) ($authorization['card_type'] ?? ''));

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
            throw new GatewayException("Paystack couldn't be reached. Please try again.");
        }

        if ($response->failed() && ! ($allowNotFound && $response->status() === 404)) {
            throw new GatewayException('Paystack said: '.($response->json('message') ?? 'the request failed').'.');
        }

        return $response;
    }
}
