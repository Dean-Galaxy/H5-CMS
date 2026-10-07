<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Content\ContentServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;

return [
    'contract' => '1.0.2',
    'workspace' => env('H5_WORKSPACE', 'unconfigured'),
    'simulation' => (bool) env('H5_SIMULATION', false),
    'local_modules' => env('H5_LOCAL_MODULES', ''),
    'local_module_providers' => [
        'content' => ContentServiceProvider::class,
        'billing' => BillingServiceProvider::class,
    ],
    'local_us_test_region' => (bool) env('H5_LOCAL_US_TEST_REGION', false),
    'media_root' => env('H5_MEDIA_ROOT', storage_path('app/private/media')),
    'mail_preview_root' => base_path('../.runtime/mail'),
    'modules' => [
        'identity' => ['provider' => IdentityServiceProvider::class, 'ready' => true],
        'content' => ['provider' => ContentServiceProvider::class, 'ready' => true],
        'billing' => ['provider' => null, 'ready' => false],
        'audience' => ['provider' => null, 'ready' => false],
        'acquisition' => ['provider' => null, 'ready' => false],
    ],
];
