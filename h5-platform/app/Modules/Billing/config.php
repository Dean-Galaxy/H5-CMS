<?php

return [
    'driver' => env('H5_BILLING_DRIVER', 'unconfigured'),
    'stripe_secret' => env('H5_BILLING_STRIPE_TEST_SECRET'),
    'stripe_webhook_secret' => env('H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET'),
    'api_version' => '2026-08-26.dahlia',
    // D02 remains a proposal until 00 publishes the decision. Tests explicitly opt in.
    'quote_policy_approved' => false,
    'offer_ttl_seconds' => 1800,
    'checkout_ttl_seconds' => 1800,
];
