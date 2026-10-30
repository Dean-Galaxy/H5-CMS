<?php

use App\Modules\Acquisition\AcquisitionServiceProvider;
use App\Modules\Audience\AudienceServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Content\ContentServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;

return [
    'contract' => '1.0.5',
    'workspace' => env('H5_WORKSPACE', 'unconfigured'),
    'controlled_staging' => env('H5_CONTROLLED_STAGING', false),
    'simulation' => env('H5_SIMULATION', false),
    'local_modules' => env('H5_LOCAL_MODULES', ''),
    'local_module_providers' => [
        'content' => ContentServiceProvider::class,
        'billing' => BillingServiceProvider::class,
        'audience' => AudienceServiceProvider::class,
        'acquisition' => AcquisitionServiceProvider::class,
    ],
    'local_us_test_region' => env('H5_LOCAL_US_TEST_REGION', false),
    'measurement_c_experience' => env('H5_C_EXPERIENCE_ENABLED', false),
    'measurement_c_registration' => env('H5_C_REGISTRATION_ENABLED', false),
    'media_root' => env('H5_MEDIA_ROOT', storage_path('app/private/media')),
    'mail_preview_root' => base_path('../.runtime/mail'),
    'modules' => [
        'identity' => ['provider' => IdentityServiceProvider::class, 'ready' => true],
        'content' => ['provider' => ContentServiceProvider::class, 'ready' => true],
        'billing' => ['provider' => BillingServiceProvider::class, 'ready' => true],
        'audience' => ['provider' => AudienceServiceProvider::class, 'ready' => true],
        'acquisition' => ['provider' => null, 'ready' => false],
    ],
];
