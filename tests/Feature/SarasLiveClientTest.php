<?php

use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use App\Models\ProjectProgressReport;
use App\Services\Saras\SarasLiveClient;
use App\Services\TrackAI\Mappers\ProjectProgressWorkflowPayloadMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function sarasTestTokenManager(): SarasTokenManagerInterface
{
    return new class implements SarasTokenManagerInterface
    {
        public function getAccessToken(): string
        {
            return 'test-token';
        }

        public function invalidateToken(): void {}
    };
}

function sarasCountingTokenManager(): SarasTokenManagerInterface
{
    return new class implements SarasTokenManagerInterface
    {
        public int $invalidations = 0;

        public function getAccessToken(): string
        {
            return 'test-token';
        }

        public function invalidateToken(): void
        {
            $this->invalidations++;
        }
    };
}

test('Saras validation responses are translated without escaping as request exceptions', function () {
    Http::fake([
        'https://saras.test/process/workflows/executeWorkflow*' => Http::response([
            'errorCode' => 904,
            'msg' => 'Request body does not match the schema.',
        ], 400),
    ]);

    $client = new SarasLiveClient(sarasTestTokenManager(), 'https://saras.test', 10, 1, 0);

    expect(fn () => $client->executeWorkflow(
        workflowId: 'workflow-id',
        otherDetails: ['processId' => 'process-id'],
        payload: ['engineersRemarks' => 'Test'],
    ))->toThrow(SarasApiException::class, 'Request body does not match the schema.');
});

test('Saras forbidden responses do not invalidate a valid token', function () {
    Http::fake([
        'https://saras.test/process/projects/getProjectsForUser*' => Http::response([
            'errorCode' => 915,
            'msg' => 'Access Denied by IAM Engine.',
        ], 403),
    ]);

    $tokenManager = sarasCountingTokenManager();
    $client = new SarasLiveClient($tokenManager, 'https://saras.test', 10, 1, 0);

    expect(fn () => $client->getProjectsForUser())
        ->toThrow(SarasApiException::class, 'Access Denied by IAM Engine.')
        ->and($tokenManager->invalidations)->toBe(0);
});

test('Saras unauthorized responses invalidate the token', function () {
    Http::fake([
        'https://saras.test/process/projects/getProjectsForUser*' => Http::response([
            'msg' => 'Unauthorized',
        ], 401),
    ]);

    $tokenManager = sarasCountingTokenManager();
    $client = new SarasLiveClient($tokenManager, 'https://saras.test', 10, 1, 0);

    expect(fn () => $client->getProjectsForUser())
        ->toThrow(SarasApiException::class, 'Unauthorized')
        ->and($tokenManager->invalidations)->toBe(1);
});

test('Saras parent process errors are treated as validation without invalidating the token', function () {
    Http::fake([
        'https://saras.test/process/createProcess' => Http::response([
            'errorCode' => 1221,
            'msg' => 'We could not find relevant process for the provided ID',
        ], 401),
    ]);

    $tokenManager = sarasCountingTokenManager();
    $client = new SarasLiveClient($tokenManager, 'https://saras.test', 10, 1, 0);

    expect(fn () => $client->createProcess(
        subProjectId: 'attendance-subproject-id',
        fields: ['contractId' => 'project-id'],
        parentProcessId: 'project-id',
    ))
        ->toThrow(SarasApiException::class, 'We could not find relevant process for the provided ID')
        ->and($tokenManager->invalidations)->toBe(0);
});

test('workflow requests use the current Saras process workflow schema', function () {
    Http::fake([
        'https://saras.test/process/workflows/executeWorkflow*' => Http::response([
            'runId' => [
                'id' => 'run-id',
                'state' => 'INITIALISED',
            ],
        ]),
    ]);

    $client = new SarasLiveClient(sarasTestTokenManager(), 'https://saras.test', 10, 1, 0);

    $client->executeWorkflow(
        workflowId: 'workflow-id',
        otherDetails: [
            'initiator' => 'INITIATOR_PROCESS',
            'processId' => 'process-id',
            'initiatorMeta' => ['stageKey' => 'stage-key'],
        ],
        payload: ['engineersRemarks' => 'Test remarks'],
    );

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $otherDetails = (array) $data['otherDetails'];
        $workflowData = (array) $otherDetails['data'];

        return $data['workflowId'] === 'workflow-id'
            && $data['processId'] === 'process-id'
            && $data['stageKey'] === 'stage-key'
            && $otherDetails['initiator'] === 'INITIATOR_PROCESS'
            && $otherDetails['processId'] === 'process-id'
            && $otherDetails['initiatorMeta'] === ['stageKey' => 'stage-key']
            && (array) ((array) $workflowData['engineersRemarks'])['data'] === [
                'value' => 'Test remarks',
            ]
            && ! array_key_exists('payload', $data);
    });
});

test('workflow payload excludes image slots unless explicitly enabled', function () {
    config()->set('saras.workflows.send_image_payload', false);

    $report = ProjectProgressReport::factory()->make([
        'previous_progress_file_ids' => ['previous-file-id'],
        'current_progress_file_ids' => ['current-file-id'],
        'remarks' => 'Test remarks',
    ]);

    $payload = app(ProjectProgressWorkflowPayloadMapper::class)->map($report);

    expect($payload['payload'])->toBe([
        'engineersRemarks' => 'Test remarks',
    ]);
});

test('child processes include the contract process as parent metadata', function () {
    Http::fake([
        'https://saras.test/process/createProcess' => Http::response([
            'process' => ['id' => 'child-process-id'],
        ]),
    ]);

    $client = new SarasLiveClient(sarasTestTokenManager(), 'https://saras.test', 10, 1, 0);

    $client->createProcess(
        subProjectId: 'progress-subproject-id',
        fields: ['currentMilestone' => 'Foundation'],
        parentProcessId: 'contract-process-id',
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://saras.test/process/createProcess'
        && $request['subProjectId'] === 'progress-subproject-id'
        && $request['fields'] === ['currentMilestone' => 'Foundation']
        && $request['metaDetails'] === ['parentId' => 'contract-process-id']);
});

test('storage uploads are scoped to a Saras subproject', function () {
    Http::fake([
        'https://saras.test/process/knowledges/createStorage*' => Http::response([
            'files' => [['id' => 'file-id']],
        ]),
    ]);

    $client = new SarasLiveClient(sarasTestTokenManager(), 'https://saras.test', 10, 1, 0);
    $file = UploadedFile::fake()->image('progress.jpg');

    $client->uploadFiles([$file], 'trackdata-subproject-id');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://saras.test/process/knowledges/createStorage?subProjectId=trackdata-subproject-id');
});

test('file URLs are requested through the scoped Saras storage endpoint', function () {
    Http::fake([
        'https://saras.test/process/knowledges/urlStorage' => Http::response([
            'urls' => [[
                'fileId' => 'file-id',
                'url' => 'https://storage.test/file-id',
            ]],
        ]),
    ]);

    $client = new SarasLiveClient(sarasTestTokenManager(), 'https://saras.test', 10, 1, 0);

    $client->getFileUrl('progress-subproject-id', 'file-id');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://saras.test/process/knowledges/urlStorage'
        && $request['subProjectId'] === 'progress-subproject-id'
        && $request['fileId'] === 'file-id');
});

test('workflow runs use direct Saras query parameters instead of filters JSON', function () {
    Http::fake([
        'https://saras.test/process/workflows/getWorkflowRuns*' => Http::response([
            'meta' => ['page' => '1', 'totalCount' => '0', 'totalPages' => '1'],
            'runs' => [],
        ]),
    ]);

    $client = new SarasLiveClient(sarasTestTokenManager(), 'https://saras.test', 10, 1, 0);

    $client->getWorkflowRuns(1, 5, [
        'subProjectId' => 'progress-subproject-id',
        'processId' => 'process-id',
        'workflowId' => 'workflow-id',
    ]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === [
            'page' => '1',
            'perPageCount' => '5',
            'subProjectId' => 'progress-subproject-id',
            'processId' => 'process-id',
            'workflowId' => 'workflow-id',
        ] && ! array_key_exists('filters', $query);
    });
});
