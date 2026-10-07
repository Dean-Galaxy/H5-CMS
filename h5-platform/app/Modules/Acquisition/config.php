<?php

return [
    // Asset registration never turns external delivery on.
    'transport' => env('ACQUISITION_TRANSPORT', 'disabled'),
    'external_enabled' => (bool) env('ACQUISITION_EXTERNAL_ENABLED', false),
    'platform_reviewed' => (bool) env('ACQUISITION_PLATFORM_REVIEWED', false),
    'meta_api_version' => env('ACQUISITION_META_API_VERSION', ''),
    // A private, non-web-readable JSON map keyed by pixel UUID + immutable version.
    'credentials_file' => env('ACQUISITION_CREDENTIALS_FILE', ''),
    'max_attempts' => 6,
];
