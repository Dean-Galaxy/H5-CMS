<?php

// Shared provider selection. Legacy Stripe test installations keep their default.
return [
    'new_purchase_provider' => env('H5_BILLING_NEW_PURCHASE_PROVIDER',
        env('H5_BILLING_DRIVER', 'unconfigured') === 'stripe_test' ? 'stripe' : 'unconfigured'),
    'paypal' => [
        'enabled' => env('H5_BILLING_PAYPAL_SANDBOX_ENABLED', false),
        'client_id' => env('H5_BILLING_PAYPAL_SANDBOX_CLIENT_ID'),
        'client_secret' => env('H5_BILLING_PAYPAL_SANDBOX_CLIENT_SECRET'),
        'webhook_id' => env('H5_BILLING_PAYPAL_SANDBOX_WEBHOOK_ID'),
        'merchant_id' => env('H5_BILLING_PAYPAL_SANDBOX_MERCHANT_ID'),
        'merchant_scope' => env('H5_BILLING_PAYPAL_SANDBOX_MERCHANT_SCOPE'),
        'product_id' => env('H5_BILLING_PAYPAL_SANDBOX_PRODUCT_ID'),
    ],
];
