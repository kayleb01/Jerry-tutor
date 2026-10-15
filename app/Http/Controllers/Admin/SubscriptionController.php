<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionsOverview;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(SubscriptionsOverview $overview): Response
    {
        return Inertia::render('admin/subscriptions/index', [
            'stats' => $overview->stats(),
            'plans' => $overview->plans(),
            'attention' => $overview->attention(),
            'payments' => $overview->recentPayments(),
        ]);
    }
}
