<?php

use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'viewers'],
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'viewers'],
        'staff' => ['driver' => 'session', 'provider' => 'staff'],
    ],
    'providers' => [
        'viewers' => ['driver' => 'eloquent', 'model' => Viewer::class],
        'staff' => ['driver' => 'eloquent', 'model' => Staff::class],
    ],
    'passwords' => ['viewers' => [
        'provider' => 'viewers', 'table' => 'identity_password_reset_tokens', 'expire' => 30, 'throttle' => 60,
    ]],
    'password_timeout' => 900,
];
