<?php

use App\Modules\Identity\IdentityServiceProvider;

return [
    'contract' => '1.0.1',
    'workspace' => env('H5_WORKSPACE', 'unconfigured'),
    'simulation' => (bool) env('H5_SIMULATION', false),
    'local_modules' => env('H5_LOCAL_MODULES', ''),
    'local_module_providers' => [
        'content' => App\Modules\Content\ContentServiceProvider::class,
    ],
    'local_us_test_region' => (bool) env('H5_LOCAL_US_TEST_REGION', false),
    'media_root' => env('H5_MEDIA_ROOT', storage_path('app/private/media')),
    'mail_preview_root' => base_path('../.runtime/mail'),
    'modules' => [
        'identity' => ['provider' => IdentityServiceProvider::class, 'ready' => true],
        'content' => ['provider' => null, 'ready' => false],
        'billing' => ['provider' => null, 'ready' => false],
        'audience' => ['provider' => null, 'ready' => false],
        'acquisition' => ['provider' => null, 'ready' => false],
    ],
];
