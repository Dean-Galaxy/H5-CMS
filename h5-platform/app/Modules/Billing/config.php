<?php

return [
    'driver' => env('H5_BILLING_DRIVER', 'unconfigured'),
    'stripe_secret' => env('H5_BILLING_STRIPE_TEST_SECRET'),
    'stripe_webhook_secret' => env('H5_BILLING_STRIPE_TEST_WEBHOOK_SECRET'),
    // Optional official sandbox clock; never a local time override.
    'test_clock_id' => env('H5_BILLING_TEST_CLOCK_ID'),
    'api_version' => '2026-08-26.dahlia',
    // D02 frozen by 00 in docs/contracts/billing-lifecycle-v1.md (d5de098).
    'quote_policy_approved' => true,
    'offer_ttl_seconds' => 1800,
    'checkout_ttl_seconds' => 1860,
];
