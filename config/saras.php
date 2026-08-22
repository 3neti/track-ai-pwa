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
    | Saras Login Credentials
    |--------------------------------------------------------------------------
    |
    | Used by CLI lifecycle diagnostics to refresh a user's Saras token.
    | Web login still uses the credentials submitted by the user.
    |
    */

    'username' => env('SARAS_USERNAME'),
    'password' => env('SARAS_PASSWORD'),

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
    | Default Contract ID
    |--------------------------------------------------------------------------
    |
    | Temporary default contract ID until Saras provisions DPWH contracts.
    |
    */

    'default_contract_id' => env('SARAS_CONTRACT_ID_DEFAULT'),

    /*
    |--------------------------------------------------------------------------
    | Track AI Project ID
    |--------------------------------------------------------------------------
    |
    | The Saras project ID for the Track AI module.
    |
    */

    'project_id' => env('SARAS_PROJECT_ID', 'd3999d8f-c367-4213-a630-a528cfdd7eb6'),

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
        'project_progress' => env('SARAS_SUBPROJECT_PROJECT_PROGRESS', '794a98cf-afea-49f9-aa02-c3a430ba714f'),
        'contract_ai' => env('SARAS_SUBPROJECT_CONTRACT_AI', 'acfdb45a-f4fd-4e25-8e52-de8ae6ff5b99'),
    ],

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
    | Completion Workflow Configuration
    |--------------------------------------------------------------------------
    |
    | Workflow ID and stage key for the ProjectProgress completion workflow
    | ("Construction Progress Comparison").
    |
    */

    'workflows' => [
        'completion_id' => env('SARAS_WORKFLOW_COMPLETION_ID', 'd702fb25-51ae-4d7f-88fc-132d555b2f00'),
        'completion_stage_key' => env('SARAS_WORKFLOW_COMPLETION_STAGE_KEY', 'stage_1779863565116_eqt6'),
        'certificate_id' => env('SARAS_WORKFLOW_CERTIFICATE_ID', '3406f390-ce85-4b32-8531-8b90c837dcb4'),
        'send_image_payload' => env('SARAS_SEND_IMAGE_PAYLOAD_TO_WORKFLOW', false),
        'attach_stage_files' => env('SARAS_ATTACH_STAGE_FILES', false),
        'trigger_missing_run_on_poll' => env('SARAS_TRIGGER_MISSING_WORKFLOW_RUN_ON_POLL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload Map
    |--------------------------------------------------------------------------
    |
    | Human-readable inventory used by Saras API X-Ray during joint payload
    | confirmation calls. Keep secrets out of samples; ApiTraceRecorder still
    | redacts actual request data.
    |
    */

    'payload_map' => [
        'userLogin' => [
            'method' => 'POST',
            'endpoint' => '/users/userLogin',
            'config_keys' => ['saras.username', 'saras.password'],
            'request_shape' => [
                'client_id' => 'string',
                'client_secret' => '[REDACTED]',
            ],
            'response_fields_used' => ['access_token', 'token', 'expires_in', 'expiresIn'],
        ],
        'getUserDetails' => [
            'method' => 'GET',
            'endpoint' => '/users/getUserDetails',
            'config_keys' => [],
            'request_shape' => [],
            'response_fields_used' => ['id', 'name', 'tenantId.id', 'tenantId.name'],
        ],
        'getProjectsForUser' => [
            'method' => 'GET',
            'endpoint' => '/process/projects/getProjectsForUser',
            'config_keys' => [],
            'request_shape' => ['page' => 'integer', 'perPageCount' => 'integer'],
            'response_fields_used' => ['projects[].id', 'projects[].projectMeta.name', 'projects[].subProjects'],
        ],
        'getProcess' => [
            'method' => 'GET',
            'endpoint' => '/process/getProcess',
            'config_keys' => ['saras.subproject_ids.contract_ai', 'saras.subproject_ids.project_progress'],
            'request_shape' => ['filters' => ['subProjectId_id' => 'uuid']],
            'response_fields_used' => ['processes[].id', 'processes[].fields', 'processes[].metaDetails'],
        ],
        'createProcess' => [
            'method' => 'POST',
            'endpoint' => '/process/createProcess',
            'config_keys' => ['saras.subproject_ids.attendance', 'saras.subproject_ids.project_progress'],
            'request_shape' => ['subProjectId' => 'uuid', 'fields' => 'object', 'metaDetails.parentId' => 'uuid|null'],
            'response_fields_used' => ['processId', 'id', 'entryId', 'success'],
        ],
        'uploadFiles' => [
            'method' => 'POST',
            'endpoint' => '/process/knowledges/createSignedStorage + S3 POST + /process/knowledges/closeSignedStorage',
            'config_keys' => ['saras.subproject_ids.trackdata', 'saras.subproject_ids.project_progress'],
            'request_shape' => [
                'createSignedStorage' => ['subProjectId' => 'uuid', 'fileName' => 'string', 'mimeType' => 'string'],
                's3' => ['url' => 'aws.url', 'fields' => 'aws.fields + file multipart field'],
                'closeSignedStorage' => ['fileId' => 'uuid', 'subProjectId' => 'uuid'],
            ],
            'response_fields_used' => ['file.id', 'aws.url', 'aws.fields'],
        ],
        'updateFiles' => [
            'method' => 'POST',
            'endpoint' => '/process/updateFiles',
            'config_keys' => ['saras.subproject_ids.project_progress', 'saras.workflows.completion_stage_key'],
            'request_shape' => ['processId' => 'uuid', 'stageKey' => 'string', 'subProjectId' => 'uuid', 'files' => 'object'],
            'response_fields_used' => ['success', 'message'],
        ],
        'executeWorkflow' => [
            'method' => 'POST',
            'endpoint' => '/process/workflows/executeWorkflow',
            'config_keys' => ['saras.workflows.completion_id', 'saras.workflows.completion_stage_key', 'saras.workflows.trigger_missing_run_on_poll'],
            'request_shape' => ['workflowId' => 'uuid', 'processId' => 'uuid', 'stageKey' => 'string', 'otherDetails' => 'object'],
            'response_fields_used' => ['runId.id', 'executionId', 'workflowId'],
        ],
        'getWorkflowRuns' => [
            'method' => 'GET',
            'endpoint' => '/process/workflows/getWorkflowRuns',
            'config_keys' => ['saras.subproject_ids.project_progress', 'saras.workflows.completion_id'],
            'request_shape' => ['page' => 'integer', 'perPageCount' => 'integer', 'processId' => 'uuid|null', 'workflowId' => 'uuid|null', 'runId' => 'uuid|null'],
            'response_fields_used' => ['runs[].id', 'runs[].state', 'runs[].flowState', 'runs[].updatedTs'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Location Trust / Anti GPS Spoofing
    |--------------------------------------------------------------------------
    */

    'location_trust' => [
        'mode' => env('LOCATION_TRUST_MODE', 'audit'),
        'send_to_saras' => env('LOCATION_TRUST_SEND_TO_SARAS', false),
        'max_accuracy_meters' => (int) env('LOCATION_TRUST_MAX_ACCURACY_METERS', 100),
        'max_position_age_seconds' => (int) env('LOCATION_TRUST_MAX_POSITION_AGE_SECONDS', 120),
        'max_speed_kmh' => (int) env('LOCATION_TRUST_MAX_SPEED_KMH', 180),
    ],

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
        'relaxed_progress_milestone_rules' => env('SARAS_RELAXED_PROGRESS_MILESTONE_RULES', true),
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
