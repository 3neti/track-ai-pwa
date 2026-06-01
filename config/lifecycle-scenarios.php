<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Scenario Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'user_id' => (int) env('LIFECYCLE_DEFAULT_USER_ID', 1),
        'project_id' => env('LIFECYCLE_DEFAULT_PROJECT_ID'),
        'timeout' => (int) env('LIFECYCLE_TIMEOUT', 300),
        'poll' => (int) env('LIFECYCLE_POLL', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Scenarios
    |--------------------------------------------------------------------------
    */

    'scenarios' => [

        'basic_progress' => [
            'label' => 'Basic Progress Report',
            'category' => 'smoke',
            'mode' => 'default',
            'risk' => 'low',
            'tags' => ['progress', 'smoke'],
            'description' => 'Submit a progress report to Saras.',
            'current_milestone' => 'Foundation',
            'remarks' => 'Lifecycle test - basic progress submission.',
        ],

        'full_lifecycle' => [
            'label' => 'Full Progress Lifecycle',
            'category' => 'smoke',
            'mode' => 'full_lifecycle',
            'risk' => 'medium',
            'tags' => ['progress', 'workflow', 'smoke'],
            'description' => 'Submit progress, trigger AI workflow, poll to completion.',
            'current_milestone' => 'Foundation',
            'remarks' => 'Full lifecycle test - submit, trigger, poll.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scenario Groups
    |--------------------------------------------------------------------------
    */

    'scenario_groups' => [
        'smoke' => [
            'categories' => ['smoke'],
        ],
        'progress' => [
            'tags' => ['progress'],
        ],
    ],

];
