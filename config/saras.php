<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Saras API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Saras API endpoints.
    |
    */

    'base_url' => env('SARAS_BASE_URL', 'https://api.saras.example.com'),

    /*
    |--------------------------------------------------------------------------
    | Saras Mode
    |--------------------------------------------------------------------------
    |
    | Determines whether to use stub responses or make actual API calls.
    | Supported: "stub", "live"
    |
    */

    'mode' => env('SARAS_MODE', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | API Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for API requests.
    |
    */

    'timeout' => env('SARAS_TIMEOUT', 30),

];
