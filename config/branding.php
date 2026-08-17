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

    'remote' => [
        'enabled' => env('BRAND_REMOTE_ENABLED', true),
        'cache_ttl_seconds' => (int) env('BRAND_REMOTE_CACHE_TTL_SECONDS', 300),
    ],

];
