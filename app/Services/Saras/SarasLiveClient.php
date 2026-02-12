<?php

namespace App\Services\Saras;

use App\Contracts\SarasClientInterface;
use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\ProcessResponse;
use App\Services\Saras\DTO\ProjectsResponse;
use App\Services\Saras\DTO\UserDetails;
use App\Services\Saras\DTO\WorkflowResponse;
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

    public function createProcess(string $subProjectId, array $fields, ?string $idempotencyKey = null): ProcessResponse
    {
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

        $response = $this->makeRequest(
            method: 'POST',
            endpoint: '/process/createProcess',
            requestId: $requestId,
            data: [
                'subProjectId' => $subProjectId,
                'fields' => $fields,
            ],
            headers: $headers,
        );

        return ProcessResponse::fromArray($response);
    }

    public function uploadFiles(array $files): FileUploadResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: uploadFiles', [
            'request_id' => $requestId,
            'endpoint' => '/process/knowledges/createStorage',
            'file_count' => count($files),
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

            // pluginName sent as query parameter for multipart upload
            $pluginName = config('saras.plugin_name', 'knowledgeRepo');
            $response = $request->post("/process/knowledges/createStorage?pluginName={$pluginName}");

            Log::info('Saras API: uploadFiles response', [
                'request_id' => $requestId,
                'status' => $response->status(),
            ]);

            if (! $response->successful()) {
                $this->handleErrorResponse($response, '/process/knowledges/createStorage', $requestId);
            }

            return FileUploadResponse::fromArray($response->json());

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
                'otherDetails' => (object) $otherDetails,
                'payload' => (object) $payload,
            ],
        );

        return WorkflowResponse::fromArray(array_merge($response, ['workflowId' => $workflowId]));
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
        try {
            $token = $this->tokenManager->getAccessToken();

            $request = $this->client()
                ->withToken($token)
                ->withHeaders($headers);

            if (! empty($query)) {
                $endpoint .= '?'.http_build_query($query);
            }

            $response = match (strtoupper($method)) {
                'GET' => $request->get($endpoint),
                'POST' => $request->post($endpoint, $data),
                'PUT' => $request->put($endpoint, $data),
                'DELETE' => $request->delete($endpoint),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            Log::info('Saras API: Response received', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            if (! $response->successful()) {
                $this->handleErrorResponse($response, $endpoint, $requestId);
            }

            return $response->json() ?? [];

        } catch (ConnectionException $e) {
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

        if ($status === 422) {
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
            })
            ->acceptJson()
            ->asJson();
    }
}
