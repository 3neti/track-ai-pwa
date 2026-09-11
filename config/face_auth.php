<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Face Authentication Provider
    |--------------------------------------------------------------------------
    |
    | Supported providers:
    | - stub: local testing responses
    | - hyperverge_direct: Track AI calls HyperVerge directly
    | - saras: Saras owns face registration and face login verification
    |
    */

    'provider' => env('FACE_AUTH_PROVIDER', 'saras'),

    'capture_provider' => env('FACE_CAPTURE_PROVIDER', 'browser'),

    'saras' => [
        'register_path' => env('SARAS_FACE_REGISTER_PATH', '/users/registerFaceForFaceAuthentication'),
        'login_path' => env('SARAS_FACE_LOGIN_PATH', '/users/loginWithFace'),
        'status_path' => env('SARAS_FACE_STATUS_PATH', '/users/checkSamlLoginEnabled'),
    ],

    'hyperverge_capture' => [
        'enabled' => env('HYPERVERGE_CAPTURE_ENABLED', true),
        'sdk_url' => env('HYPERVERGE_WEB_SDK_URL', 'https://hv-web-sdk-cdn.hyperverge.co/hyperverge-web-sdk@8.11.5/src/sdk.min.js'),
        'auth_url' => env('HYPERVERGE_AUTH_URL', 'https://auth.hyperverge.co/login'),
        'workflow' => env('HYPERVERGE_CAPTURE_WORKFLOW', env('HYPERVERGE_ENROLL_WORKFLOW', 'enrol')),
        'token_expiry_seconds' => (int) env('HYPERVERGE_TOKEN_EXPIRY_SECONDS', 900),
    ],
];
