<?php

return [

    /*
    | The gateway new checkouts go through ("paystack" or "flutterwave"). Existing subscriptions
    | always go back to the gateway that created them.
    */
    'default' => env('PAYMENT_GATEWAY', 'paystack'),

    /* Every subscription is in naira; amounts are stored as integer kobo. */
    'currency' => 'NGN',

    /* Days of access after a missed renewal before a subscription expires. */
    'grace_days' => (int) env('PAYMENT_GRACE_DAYS', 7),

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => 'https://api.paystack.co',
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
        'base_url' => 'https://api.flutterwave.com/v3',
    ],

    /*
    | Defaults for PlanSeeder. The real price lives in each gateway's dashboard; the local amount
    | must match it before a plan can be linked in Admin → Billing settings.
    */
    'plans' => [
        'standard' => [
            'amount' => (int) env('PLAN_STANDARD_AMOUNT_KOBO', 500000),
            'paystack_plan_code' => env('PLAN_STANDARD_PAYSTACK_CODE'),
            'flutterwave_plan_id' => env('PLAN_STANDARD_FLUTTERWAVE_ID'),
        ],
        'family' => [
            'amount' => (int) env('PLAN_FAMILY_AMOUNT_KOBO', 1200000),
            'max_students' => (int) env('PLAN_FAMILY_MAX_STUDENTS', 4),
            'paystack_plan_code' => env('PLAN_FAMILY_PAYSTACK_CODE'),
            'flutterwave_plan_id' => env('PLAN_FAMILY_FLUTTERWAVE_ID'),
        ],
    ],

];
