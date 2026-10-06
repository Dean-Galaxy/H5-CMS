<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'passwords' => 'viewers', 'username' => 'email', 'email' => 'email',
    'lowercase_usernames' => true, 'home' => '/identity/account', 'prefix' => 'api/v1/auth',
    'domain' => null, 'middleware' => ['web'], 'views' => false,
    'limiters' => ['login' => 'identity-login'],
    'features' => [Features::registration(), Features::resetPasswords(), Features::emailVerification()],
];
