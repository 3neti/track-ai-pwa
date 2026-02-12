<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hyperverge API Mode
    |--------------------------------------------------------------------------
    |
    | Set to 'stub' for development/testing (returns mock responses),
    | or 'live' for production (makes real API calls).
    |
    */

    'mode' => env('HYPERVERGE_MODE', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Hyperverge API Configuration
    |--------------------------------------------------------------------------
    */

    'base_url' => env('HYPERVERGE_BASE_URL', 'https://ind.idv.hyperverge.co/v1'),

    'app_id' => env('HYPERVERGE_APP_ID'),

    'app_key' => env('HYPERVERGE_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | HTTP request timeout in seconds.
    |
    */

    'timeout' => env('HYPERVERGE_TIMEOUT', 30),

];
