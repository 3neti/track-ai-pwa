<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand Identity
    |--------------------------------------------------------------------------
    |
    | These values let deployments swap app/customer branding without touching
    | Vue components. Logo paths may point to public assets or remote URLs.
    |
    */

    'name' => env('BRAND_NAME', env('APP_NAME', 'Track AI')),

    'short_name' => env('BRAND_SHORT_NAME', 'Track AI'),

    'square_logo' => env('BRAND_SQUARE_LOGO'),

    'rectangle_logo' => env('BRAND_RECTANGLE_LOGO'),

    'authenticated' => [
        'name' => env('AUTHENTICATED_BRAND_NAME', 'DPWH'),
        'short_name' => env('AUTHENTICATED_BRAND_SHORT_NAME', 'DPWH'),
        'square_logo' => env('AUTHENTICATED_BRAND_SQUARE_LOGO', 'https://publicassets.sarasfinance.com/client/dpwh/favicon17.png'),
        'rectangle_logo' => env('AUTHENTICATED_BRAND_RECTANGLE_LOGO'),
    ],

    'login_rotation' => [
        'interval_ms' => (int) env('BRAND_LOGIN_ROTATION_INTERVAL_MS', 10000),
        'logos' => [
            [
                'name' => 'Saras',
                'square_logo' => null,
                'rectangle_logo' => env('BRAND_LOGIN_SARAS_LOGO', 'https://publicassets.sarasfinance.com/saras/saras.png'),
            ],
            [
                'name' => 'Capstone',
                'square_logo' => null,
                'rectangle_logo' => env('BRAND_LOGIN_CAPSTONE_LOGO', 'https://publicassets.sarasfinance.com/partner/capstone/capstone.png'),
            ],
        ],
    ],

    'remote' => [
        'enabled' => env('BRAND_REMOTE_ENABLED', true),
        'cache_ttl_seconds' => (int) env('BRAND_REMOTE_CACHE_TTL_SECONDS', 300),
    ],

];
