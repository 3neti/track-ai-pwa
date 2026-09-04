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

    'saras' => [
        'register_path' => env('SARAS_FACE_REGISTER_PATH', '/users/registerFaceForFaceAuthentication'),
        'login_path' => env('SARAS_FACE_LOGIN_PATH', '/users/loginWithFace'),
        'status_path' => env('SARAS_FACE_STATUS_PATH', '/users/checkSamlLoginEnabled'),
    ],
];
