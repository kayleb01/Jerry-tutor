<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the signed-in user the dashboard for their role.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard/'.$request->user()->role->value);
    }
}
