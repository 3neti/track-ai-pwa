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
    | Verify Endpoint Path
    |--------------------------------------------------------------------------
    |
    | The API endpoint path for face verification.
    |
    */

    'verify_path' => env('HYPERVERGE_VERIFY_PATH', '/photo/verifyPair'),

    'liveness_path' => env('HYPERVERGE_LIVENESS_PATH', '/checkLiveness'),

    'match_path' => env('HYPERVERGE_MATCH_PATH', '/matchFace'),

    'match_type' => env('HYPERVERGE_MATCH_TYPE', 'face_face'),

    'confidence_threshold' => (float) env('HYPERVERGE_CONFIDENCE_THRESHOLD', 85),

    'workflows' => [
        'face_auth' => env('HYPERVERGE_FACE_AUTH_WORKFLOW', 'faceAuth'),
        'enroll' => env('HYPERVERGE_ENROLL_WORKFLOW', 'enrol'),
    ],

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
