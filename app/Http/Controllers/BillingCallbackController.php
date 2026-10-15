<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingCallbackController extends Controller
{
    /**
     * Where the gateway sends the payer back after its hosted checkout.
     */
    public function __invoke(Request $request, string $gateway): RedirectResponse
    {
        abort(404);
    }
}
