<?php

namespace App\Http\Controllers;

use App\Actions\StartCheckout;
use App\Enums\Role;
use App\Models\Plan;
use App\Models\User;
use App\Payments\Exceptions\CheckoutUnavailable;
use App\Payments\Exceptions\GatewayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BillingCheckoutController extends Controller
{
    /**
     * Start paying for a plan for the chosen children, then leave for the gateway's hosted page.
     */
    public function store(Request $request, StartCheckout $startCheckout): Response|RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'integer'],
            'students' => ['present', 'array', 'max:20'],
            'students.*' => ['integer', 'distinct'],
            // Most students have no email on their account; the gateway needs one for receipts.
            'email' => $request->user()->email === null
                ? ['required', 'string', 'email', 'max:255']
                : ['prohibited'],
        ], [
            'email.required' => 'Enter an email address for your receipts.',
            'email.prohibited' => 'Receipts go to the email on your account.',
            'email.*' => 'Enter a real email address, like name@example.com.',
        ]);

        $plan = Plan::findOrFail($validated['plan']);
        $students = User::query()
            ->whereKey($validated['students'])
            ->where('role', Role::Student)
            ->get();

        if ($students->count() !== count($validated['students'])) {
            return back()->withErrors(['students' => 'You can only subscribe for your own children.']);
        }

        try {
            return Inertia::location($startCheckout->handle($request->user(), $plan, $students, billingEmail: $validated['email'] ?? null));
        } catch (CheckoutUnavailable|GatewayException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }
    }
}
