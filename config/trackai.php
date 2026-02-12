<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploads Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk to use for Track AI uploads. This allows flexible
    | configuration between local development and cloud deployment.
    |
    */

    'uploads_disk' => env('TRACKAI_UPLOADS_DISK', env('FILESYSTEM_DISK', 's3')),

    /*
    |--------------------------------------------------------------------------
    | Uploads Prefix
    |--------------------------------------------------------------------------
    |
    | The path prefix for all Track AI uploads. Files will be stored under:
    | - {prefix}/uploads/{project_id}/{ulid}.{ext}
    | - {prefix}/previews/{upload_id}.{ext}
    | - {prefix}/tmp/{client_request_id}/...
    |
    */

    'uploads_prefix' => env('TRACKAI_UPLOADS_PREFIX', 'track-ai'),

];
