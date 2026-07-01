<?php

namespace App\Services\Saras;

use App\Contracts\SarasClientInterface;
use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use App\Lifecycle\Output\SarasApiTrace;
use App\Lifecycle\Output\SarasApiTracer;
use App\Services\ApiXray\ApiTraceRecorder;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\ProcessResponse;
use App\Services\Saras\DTO\ProjectsResponse;
use App\Services\Saras\DTO\UserDetails;
use App\Services\Saras\DTO\WorkflowResponse;
use App\Services\Saras\DTO\WorkflowRunsResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SarasLiveClient implements SarasClientInterface
{
    public function __construct(
        protected SarasTokenManagerInterface $tokenManager,
        protected string $baseUrl,
        protected int $timeout,
        protected int $retryAttempts = 2,
        protected int $retryDelayMs = 500,
    ) {}

    public function isStubMode(): bool
    {
        return false;
    }

    public function getUserDetails(): UserDetails
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getUserDetails', [
            'request_id' => $requestId,
            'endpoint' => '/users/getUserDetails',
        ]);

        $response = $this->makeRequest(
            method: 'GET',
            endpoint: '/users/getUserDetails',
            requestId: $requestId,
        );

        return UserDetails::fromArray($response);
    }

    public function getProjectsForUser(int $page = 1, int $perPage = 10): ProjectsResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getProjectsForUser', [
            'request_id' => $requestId,
            'endpoint' => '/process/projects/getProjectsForUser',
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $response = $this->makeRequest(
            method: 'GET',
            endpoint: '/process/projects/getProjectsForUser',
            requestId: $requestId,
            query: [
                'page' => $page,
                'perPageCount' => $perPage,
            ],
        );

        return ProjectsResponse::fromArray($response);
    }

    public function createProcess(
        string $subProjectId,
        array $fields,
        ?string $idempotencyKey = null,
        ?string $parentProcessId = null,
    ): ProcessResponse {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: createProcess', [
            'request_id' => $requestId,
            'endpoint' => '/process/createProcess',
            'sub_project_id' => $subProjectId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $headers = [];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $data = [
            'subProjectId' => $subProjectId,
            'fields' => $fields,
        ];

        if ($parentProcessId) {
            $data['metaDetails'] = ['parentId' => $parentProcessId];
        }

        $response = $this->makeRequest(
            method: 'POST',
            endpoint: '/process/createProcess',
            requestId: $requestId,
            data: $data,
            headers: $headers,
        );

        return ProcessResponse::fromArray($response);
    }

    public function uploadFiles(array $files, string $subProjectId): FileUploadResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: uploadFiles', [
            'request_id' => $requestId,
            'endpoint' => '/process/knowledges/createStorage',
            'file_count' => count($files),
            'sub_project_id' => $subProjectId,
        ]);

        try {
            $token = $this->tokenManager->getAccessToken();

            $request = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withToken($token)
                ->acceptJson();

            // Attach each file - Saras expects 'files[]' field name
            foreach ($files as $file) {
                /** @var UploadedFile $file */
                $request = $request->attach(
                    'files[]',
                    fopen($file->getRealPath(), 'r'),
                    $file->getClientOriginalName()
                );
            }

            $uploadEndpoint = '/process/knowledges/createStorage?'.http_build_query([
                'subProjectId' => $subProjectId,
            ]);
            $startTime = microtime(true);
            $response = $request->post($uploadEndpoint);
            $durationMs = (microtime(true) - $startTime) * 1000;

            $fileNames = array_map(fn ($f) => $f->getClientOriginalName(), $files);

            Log::info('Saras API: uploadFiles response', [
                'request_id' => $requestId,
                'status' => $response->status(),
            ]);

            $responseData = $response->json() ?? [];

            $this->recordTrace(
                'POST',
                $uploadEndpoint,
                [
                    'subProjectId' => $subProjectId,
                    'files' => $fileNames,
                ],
                $response->status(),
                $responseData,
                $durationMs,
                requestSummaryOverride: [
                    'subProjectId' => $subProjectId,
                    'files' => $fileNames,
                ],
            );

            if (! $response->successful()) {
                $this->handleErrorResponse($response, '/process/knowledges/createStorage', $requestId);
            }

            return FileUploadResponse::fromArray($responseData);

        } catch (ConnectionException $e) {
            Log::error('Saras API: Connection failed', [
                'request_id' => $requestId,
                'endpoint' => '/process/knowledges/createStorage',
                'error' => $e->getMessage(),
            ]);

            throw SarasApiException::unavailable('/process/knowledges/createStorage', 'Connection failed', $e);
        }
    }

    public function executeWorkflow(?string $workflowId = null, array $otherDetails = [], array $payload = []): WorkflowResponse
    {
        $requestId = Str::uuid()->toString();
        $workflowId = $workflowId ?? config('saras.workflow_id');
        $processId = $otherDetails['processId'] ?? null;
        $stageKey = $otherDetails['initiatorMeta']['stageKey'] ?? null;
        $workflowData = array_map(
            fn (mixed $value): object => (object) [
                'data' => (object) ['value' => $value],
            ],
            $payload,
        );

        Log::info('Saras API: executeWorkflow', [
            'request_id' => $requestId,
            'endpoint' => '/process/workflows/executeWorkflow',
            'workflow_id' => $workflowId,
        ]);

        $response = $this->makeRequest(
            method: 'POST',
            endpoint: '/process/workflows/executeWorkflow',
            requestId: $requestId,
            data: [
                'workflowId' => $workflowId,
                'processId' => $processId,
                'stageKey' => $stageKey,
                'otherDetails' => (object) [
                    ...$otherDetails,
                    'data' => (object) $workflowData,
                ],
            ],
        );

        return WorkflowResponse::fromArray(array_merge($response, ['workflowId' => $workflowId]));
    }

    public function getWorkflowRuns(int $page = 1, int $perPage = 10, array $filters = []): WorkflowRunsResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getWorkflowRuns', [
            'request_id' => $requestId,
            'endpoint' => '/process/workflows/getWorkflowRuns',
            'page' => $page,
            'per_page' => $perPage,
            'filters' => $filters,
        ]);

        $query = [
            'page' => $page,
            'perPageCount' => $perPage,
        ];

        $supportedFilters = ['subProjectId', 'stageKey', 'processId', 'workflowId', 'runId'];

        foreach ($filters as $key => $value) {
            if (in_array($key, $supportedFilters, true) && $value !== '') {
                $query[$key] = $value;
            }
        }

        $response = $this->makeRequest(
            method: 'GET',
            endpoint: '/process/workflows/getWorkflowRuns',
            requestId: $requestId,
            query: $query,
        );

        return WorkflowRunsResponse::fromArray($response);
    }

    public function getProcesses(string $subProjectId, int $page = 1, int $perPage = 10): array
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getProcess', [
            'request_id' => $requestId,
            'endpoint' => '/process/getProcess',
            'sub_project_id' => $subProjectId,
        ]);

        return $this->makeRequest(
            method: 'GET',
            endpoint: '/process/getProcess',
            requestId: $requestId,
            query: [
                'page' => $page,
                'perPageCount' => $perPage,
                'filters' => json_encode(['subProjectId_id' => $subProjectId]),
            ],
        );
    }

    public function getFileUrl(string $subProjectId, string $fileId): array
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getFileUrl', [
            'request_id' => $requestId,
            'endpoint' => '/process/knowledges/urlStorage',
            'sub_project_id' => $subProjectId,
            'file_id' => $fileId,
        ]);

        return $this->makeRequest(
            method: 'POST',
            endpoint: '/process/knowledges/urlStorage',
            requestId: $requestId,
            data: [
                'subProjectId' => $subProjectId,
                'fileId' => $fileId,
            ],
        );
    }

    public function updateProcessField(string $processId, string $subProjectId, array $updates): array
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: updateProcessField', [
            'request_id' => $requestId,
            'endpoint' => '/process/updateProcessField',
            'process_id' => $processId,
        ]);

        return $this->makeRequest(
            method: 'POST',
            endpoint: '/process/updateProcessField',
            requestId: $requestId,
            data: [
                'processId' => $processId,
                'subProjectId' => $subProjectId,
                'updates' => $updates,
            ],
        );
    }

    public function updateFiles(string $processId, string $stageKey, string $subProjectId, array $files): array
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: updateFiles', [
            'request_id' => $requestId,
            'endpoint' => '/process/updateFiles',
            'process_id' => $processId,
            'stage_key' => $stageKey,
        ]);

        return $this->makeRequest(
            method: 'POST',
            endpoint: '/process/updateFiles',
            requestId: $requestId,
            data: [
                'processId' => $processId,
                'stageKey' => $stageKey,
                'subProjectId' => $subProjectId,
                'files' => $files,
            ],
        );
    }

    /**
     * Make an authenticated request to Saras API.
     *
     * @throws SarasApiException
     */
    protected function makeRequest(
        string $method,
        string $endpoint,
        string $requestId,
        array $data = [],
        array $query = [],
        array $headers = [],
    ): array {
        $startTime = microtime(true);

        try {
            $token = $this->tokenManager->getAccessToken();

            $request = $this->client()
                ->withToken($token)
                ->withHeaders($headers);

            $fullEndpoint = $endpoint;
            if (! empty($query)) {
                $fullEndpoint .= '?'.http_build_query($query);
            }

            $response = match (strtoupper($method)) {
                'GET' => $request->get($fullEndpoint),
                'POST' => $request->post($fullEndpoint, $data),
                'PUT' => $request->put($fullEndpoint, $data),
                'DELETE' => $request->delete($fullEndpoint),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            $durationMs = (microtime(true) - $startTime) * 1000;

            Log::info('Saras API: Response received', [
                'request_id' => $requestId,
                'endpoint' => $fullEndpoint,
                'status' => $response->status(),
            ]);

            $responseData = $response->json() ?? [];

            $this->recordTrace($method, $fullEndpoint, $data, $response->status(), $responseData, $durationMs);

            if (! $response->successful()) {
                $this->handleErrorResponse($response, $fullEndpoint, $requestId);
            }

            return $responseData;

        } catch (ConnectionException $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->recordTrace($method, $endpoint, $data, 0, [], $durationMs, $e->getMessage());

            Log::error('Saras API: Connection failed', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw SarasApiException::unavailable($endpoint, 'Connection failed', $e);
        }
    }

    /**
     * Handle non-successful API responses.
     *
     * @throws SarasApiException
     */
    protected function handleErrorResponse($response, string $endpoint, string $requestId): void
    {
        $status = $response->status();
        $data = $response->json();
        $data['message'] ??= $data['msg'] ?? null;
        $message = $data['message'] ?? $data['error'] ?? "Request failed with status {$status}";

        Log::error('Saras API: Error response', [
            'request_id' => $requestId,
            'endpoint' => $endpoint,
            'status' => $status,
            'message' => $message,
        ]);

        if ($status === 401 || $status === 403) {
            // Invalidate token and throw auth error
            $this->tokenManager->invalidateToken();
            throw SarasApiException::authFailed($message);
        }

        if ($status === 400 || $status === 422) {
            throw SarasApiException::validationError($endpoint, $message, $data['errors'] ?? null);
        }

        if ($status >= 500) {
            throw SarasApiException::unavailable($endpoint, $message);
        }

        throw new SarasApiException(
            message: $message,
            type: SarasApiException::TYPE_UNAVAILABLE,
            endpoint: $endpoint,
            statusCode: $status,
        );
    }

    /**
     * Record an API trace entry.
     * Always persists to DB for X-Ray. In-memory tracer is optional (for --trace/--report).
     */
    protected function recordTrace(
        string $method,
        string $endpoint,
        array $requestData,
        int $status,
        array $responseData,
        float $durationMs,
        ?string $error = null,
        ?array $requestSummaryOverride = null,
    ): void {
        // Always persist to database for X-Ray
        try {
            app(ApiTraceRecorder::class)->record(
                method: strtoupper($method),
                endpoint: $endpoint,
                requestData: $requestData,
                responseData: $responseData,
                status: $status,
                durationMs: $durationMs,
                error: $error,
            );
        } catch (\Throwable $e) {
            // Don't let trace recording break API calls
            Log::debug('ApiTraceRecorder failed: '.$e->getMessage());
        }

        // In-memory tracer for lifecycle --trace/--report
        $tracer = app(SarasApiTracer::class);

        if (! $tracer->isEnabled()) {
            return;
        }

        $requestSummary = $requestSummaryOverride ?? $this->summarizeRequest($requestData);
        $responseSummary = $this->summarizeResponse($responseData);

        $tracer->record(new SarasApiTrace(
            method: strtoupper($method),
            endpoint: $endpoint,
            status: $status,
            durationMs: $durationMs,
            requestSummary: $requestSummary,
            responseSummary: $responseSummary,
            error: $error,
            rawRequest: $requestData,
            rawResponse: $responseData,
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function summarizeRequest(array $data): array
    {
        $summary = [];

        if (isset($data['subProjectId'])) {
            $id = $data['subProjectId'];
            $label = $this->subProjectLabel($id);
            $summary['subProjectId'] = $label ? "{$id} ({$label})" : $id;
        }

        if (isset($data['workflowId'])) {
            $summary['workflowId'] = $data['workflowId'];
        }

        if (isset($data['fields']) && is_array($data['fields'])) {
            $keys = array_keys($data['fields']);
            $summary['fields'] = '{'.implode(', ', $keys).'}';
        }

        if (isset($data['otherDetails']) && is_object($data['otherDetails'])) {
            $details = (array) $data['otherDetails'];
            $parts = [];

            if (isset($details['initiator'])) {
                $parts[] = "initiator: {$details['initiator']}";
            }

            if (isset($details['processId'])) {
                $parts[] = "processId: {$details['processId']}";
            }

            if (isset($details['initiatorMeta']['stageKey'])) {
                $parts[] = "stageKey: {$details['initiatorMeta']['stageKey']}";
            }

            $summary['otherDetails'] = '{'.implode(', ', $parts).'}';
        }

        if (isset($data['payload']) && is_object($data['payload'])) {
            $payload = (array) $data['payload'];
            $keys = array_keys($payload);
            $summary['payload'] = '{'.implode(', ', $keys).'}';
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    protected function summarizeResponse(array $data): array
    {
        $summary = [];

        if (isset($data['process']['id'])) {
            $summary['process.id'] = $data['process']['id'];
        }

        if (isset($data['runId']['id'])) {
            $summary['runId.id'] = $data['runId']['id'];
        }

        if (isset($data['runId']['state'])) {
            $summary['runId.state'] = $data['runId']['state'];
        }

        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $i => $file) {
                if (isset($file['id'])) {
                    $summary["files[{$i}].id"] = $file['id'];
                }
            }
        }

        if (isset($data['meta']['totalCount'])) {
            $summary['totalCount'] = $data['meta']['totalCount'];
        }

        return $summary;
    }

    protected function subProjectLabel(string $id): ?string
    {
        return match ($id) {
            config('saras.subproject_ids.attendance') => 'Attendance',
            config('saras.subproject_ids.trackdata') => 'TrackData',
            config('saras.subproject_ids.project_progress') => 'ProjectProgress',
            default => null,
        };
    }

    /**
     * Get configured HTTP client.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->retry($this->retryAttempts, $this->retryDelayMs, function ($exception, $request) {
                // Only retry on connection errors and 5xx responses
                return $exception instanceof ConnectionException
                    || ($exception->response?->status() >= 500);
            }, throw: false)
            ->acceptJson()
            ->asJson();
    }
}
