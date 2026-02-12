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

    'base_url' => env('SARAS_BASE_URL', 'https://ind-prod.sarasfinance.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Saras Mode
    |--------------------------------------------------------------------------
    |
    | Determines whether to use stub responses or make actual API calls.
    | Supported: "stub", "live"
    |
    */

    'mode' => env('SARAS_MODE', 'live'),

    /*
    |--------------------------------------------------------------------------
    | API Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for API requests.
    |
    */

    'timeout' => env('SARAS_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Authentication Credentials
    |--------------------------------------------------------------------------
    |
    | Username and password for server-to-server OAuth2 authentication.
    | These are used to obtain access tokens from /users/userLogin.
    |
    */

    'username' => env('SARAS_USERNAME'),

    'password' => env('SARAS_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Token Cache Key
    |--------------------------------------------------------------------------
    |
    | The cache key used to store the access token.
    |
    */

    'token_cache_key' => env('SARAS_TOKEN_CACHE_KEY', 'saras:token'),

    /*
    |--------------------------------------------------------------------------
    | Default Contract ID
    |--------------------------------------------------------------------------
    |
    | Temporary default contract ID until Saras provisions DPWH contracts.
    |
    */

    'default_contract_id' => env('SARAS_CONTRACT_ID_DEFAULT'),

    /*
    |--------------------------------------------------------------------------
    | SubProject IDs
    |--------------------------------------------------------------------------
    |
    | UUIDs for different modules/subprojects in Saras.
    |
    */

    'subproject_ids' => [
        'attendance' => env('SARAS_SUBPROJECT_ATTENDANCE', '78053120-7685-42a2-b802-ca144b6ed010'),
        'trackdata' => env('SARAS_SUBPROJECT_TRACKDATA', 'efb3b7c8-f6af-479f-95e3-bd623add7c56'),
        'progress' => env('SARAS_SUBPROJECT_PROGRESS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Plugin Name
    |--------------------------------------------------------------------------
    |
    | The plugin name for file uploads to /process/knowledges/createStorage.
    | Get the correct value from Saras for your tenant.
    |
    */

    'plugin_name' => env('SARAS_PLUGIN_NAME', 'knowledgeRepo'),

    /*
    |--------------------------------------------------------------------------
    | AI Workflow Configuration
    |--------------------------------------------------------------------------
    |
    | Workflow ID for running AI analysis on uploaded images.
    |
    */

    'workflow_id' => env('SARAS_WORKFLOW_ID', 'df4b1009-8ee3-4b10-a5df-3a78b8b29739'),

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Control which Saras integrations are enabled.
    |
    */

    'feature_flags' => [
        'enabled' => env('SARAS_ENABLED', true),
        'progress_enabled' => env('SARAS_PROGRESS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for HTTP retry behavior on transient failures.
    |
    */

    'retry' => [
        'attempts' => env('SARAS_RETRY_ATTEMPTS', 2),
        'delay_ms' => env('SARAS_RETRY_DELAY_MS', 500),
    ],

];
