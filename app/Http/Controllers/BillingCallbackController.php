<?php

namespace App\Http\Controllers;

use App\Actions\ApplyGatewayEvent;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\Data\GatewayEvent;
use App\Payments\Exceptions\GatewayException;
use App\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillingCallbackController extends Controller
{
    /**
     * Where the gateway sends the payer back. The query string only says which payment to check:
     * the result always comes from asking the gateway directly.
     */
    public function __invoke(Request $request, string $gateway, PaymentGatewayManager $gateways, ApplyGatewayEvent $applyGatewayEvent): RedirectResponse
    {
        $name = PaymentGatewayName::from($gateway);
        // Paystack sends ?reference= (and trxref); Flutterwave sends ?tx_ref=.
        $reference = (string) ($request->query('reference') ?? $request->query('tx_ref') ?? $request->query('trxref'));

        $payment = Payment::query()->with('subscription.students')->where('gateway', $name)->where('reference', $reference)->first();
        abort_if($payment === null, 404);

        try {
            $applyGatewayEvent->handle($name, GatewayEvent::fromVerifiedPayment($gateways->gateway($name)->verify($reference)));
        } catch (GatewayException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "We couldn't confirm your payment yet. If you were charged, it will show here shortly."]);

            return to_route('dashboard');
        }

        $payment->refresh();

        if ($payment->status === PaymentStatus::Succeeded) {
            $names = $payment->subscription?->students->pluck('name')->join(', ', ' and ') ?: 'Your child';
            Inertia::flash('toast', ['type' => 'success', 'message' => "You're subscribed. {$names} can start their quizzes now."]);
        } else {
            Inertia::flash('toast', ['type' => 'error', 'message' => "That payment didn't go through: {$payment->failure_reason}"]);
        }

        return to_route('dashboard');
    }
}
