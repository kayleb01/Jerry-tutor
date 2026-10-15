<?php

namespace App\Payments\Data;

enum GatewayEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case SubscriptionCreated = 'subscription_created';
    case SubscriptionNotRenewing = 'subscription_not_renewing';
    case SubscriptionCancelled = 'subscription_cancelled';
}
