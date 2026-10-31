<?php

return [
    'driver' => env('GEOIP_DRIVER', 'disabled'),
    'database' => env('GEOIP_DATABASE', ''),
    'max_age_days' => env('GEOIP_MAX_AGE_DAYS', 45),
];
