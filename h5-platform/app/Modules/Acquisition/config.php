<?php

return [
    // Explicit isolated synthetic engineering only; not a real US policy.
    'measurement' => [
        'enabled' => (bool) env('ACQUISITION_C_ENABLED', false),
        'synthetic_policy' => (bool) env('ACQUISITION_C_SYNTHETIC_POLICY', false),
        'synthetic_until' => env('ACQUISITION_C_SYNTHETIC_UNTIL'),
    ],
    'registration' => [
        'observations_enabled' => (bool) env('ACQUISITION_R1_OBSERVATIONS_ENABLED', false),
        'synthetic_policy' => (bool) env('ACQUISITION_R1_SYNTHETIC_POLICY', false),
        'synthetic_from' => env('ACQUISITION_R1_SYNTHETIC_FROM'),
        'synthetic_until' => env('ACQUISITION_R1_SYNTHETIC_UNTIL'),
    ],
    'analytics_enabled' => (bool) env('ACQUISITION_ANALYTICS_ENABLED', false),
    'behavior_retention_days' => 90,
    // Asset registration never turns external delivery on.
    'transport' => env('ACQUISITION_TRANSPORT', 'disabled'),
    'external_enabled' => env('ACQUISITION_EXTERNAL_ENABLED', false),
    'platform_reviewed' => env('ACQUISITION_PLATFORM_REVIEWED', false),
    'meta_api_version' => env('ACQUISITION_META_API_VERSION', ''),
    // A private, non-web-readable JSON map keyed by pixel UUID + immutable version.
    'credentials_file' => env('ACQUISITION_CREDENTIALS_FILE', ''),
    'max_attempts' => 6,
];
