<?php

namespace App\Services\Saras;

use App\Services\Saras\DTO\AiWorkflowResponse;
use App\Services\Saras\DTO\EntryResponse;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\LoginResponse;
use App\Services\Saras\DTO\ProjectDTO;
use App\Services\Saras\DTO\UserDetails;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SarasClient
{
    protected string $baseUrl;

    protected string $mode;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('saras.base_url');
        $this->mode = config('saras.mode');
        $this->timeout = config('saras.timeout');
    }

    /**
     * Check if running in stub mode.
     */
    public function isStubMode(): bool
    {
        return $this->mode === 'stub';
    }

    /**
     * Login with username and password.
     */
    public function loginWithUserNameAndPassword(string $username, string $password): LoginResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: loginWithUserNameAndPassword', [
            'request_id' => $requestId,
            'username' => $username,
        ]);

        if ($this->isStubMode()) {
            return LoginResponse::fromArray([
                'success' => true,
                'token' => 'stub_token_'.Str::random(32),
                'user_id' => 'user_'.Str::random(8),
                'message' => 'Login successful (stub)',
            ]);
        }

        $response = $this->client()->post('/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);

        return LoginResponse::fromArray($response->json());
    }

    /**
     * Fetch user details by user ID.
     */
    public function fetchUserDetails(string $userId): UserDetails
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: fetchUserDetails', [
            'request_id' => $requestId,
            'user_id' => $userId,
        ]);

        if ($this->isStubMode()) {
            return UserDetails::fromArray([
                'user_id' => $userId,
                'username' => 'engineer_'.Str::random(4),
                'name' => 'Juan Dela Cruz',
                'email' => 'engineer@dpwh.gov.ph',
                'role' => 'engineer',
                'department' => 'DPWH Region III',
                'region' => 'Central Luzon',
            ]);
        }

        $response = $this->client()->get("/users/{$userId}");

        return UserDetails::fromArray($response->json());
    }

    /**
     * Get all assigned projects for a user.
     *
     * @return array<ProjectDTO>
     */
    public function getAllAssignedProjects(string $userId): array
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getAllAssignedProjects', [
            'request_id' => $requestId,
            'user_id' => $userId,
        ]);

        if ($this->isStubMode()) {
            return [
                ProjectDTO::fromArray([
                    'external_id' => 'PROJ-2024-001',
                    'contract_id' => 'CONTRACT-R3-2024-0156',
                    'name' => 'Rehabilitation of National Road Section - Bulacan',
                    'description' => 'Rehabilitation and improvement of 5.2km national road section from Barangay San Jose to Barangay Malolos.',
                    'status' => 'active',
                    'location' => 'Bulacan, Region III',
                    'start_date' => '2024-01-15',
                    'end_date' => '2024-12-31',
                ]),
                ProjectDTO::fromArray([
                    'external_id' => 'PROJ-2024-002',
                    'contract_id' => 'CONTRACT-R3-2024-0189',
                    'name' => 'Bridge Construction - Pampanga River',
                    'description' => 'Construction of new 120-meter bridge crossing Pampanga River connecting Angeles City to Mexico.',
                    'status' => 'active',
                    'location' => 'Pampanga, Region III',
                    'start_date' => '2024-03-01',
                    'end_date' => '2025-06-30',
                ]),
                ProjectDTO::fromArray([
                    'external_id' => 'PROJ-2024-003',
                    'contract_id' => 'CONTRACT-R3-2024-0201',
                    'name' => 'Flood Control Project - Nueva Ecija',
                    'description' => 'Implementation of flood mitigation infrastructure along major waterways in Cabanatuan City.',
                    'status' => 'active',
                    'location' => 'Nueva Ecija, Region III',
                    'start_date' => '2024-02-01',
                    'end_date' => '2024-11-30',
                ]),
            ];
        }

        $response = $this->client()->get("/users/{$userId}/projects");

        return array_map(
            fn (array $project) => ProjectDTO::fromArray($project),
            $response->json('data', [])
        );
    }

    /**
     * Create an entry (attendance, upload metadata, progress update).
     */
    public function createAnEntry(array $data, string $idempotencyKey): EntryResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: createAnEntry', [
            'request_id' => $requestId,
            'idempotency_key' => $idempotencyKey,
            'entry_type' => $data['type'] ?? 'unknown',
            'contract_id' => $data['contract_id'] ?? null,
        ]);

        if ($this->isStubMode()) {
            return EntryResponse::fromArray([
                'success' => true,
                'entry_id' => 'entry_'.Str::random(12),
                'message' => 'Entry created successfully (stub)',
                'created_at' => now()->toIso8601String(),
            ]);
        }

        $response = $this->client()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('/entries', $data);

        return EntryResponse::fromArray($response->json());
    }

    /**
     * Upload a file.
     */
    public function uploadFile(UploadedFile $file, array $metadata, string $idempotencyKey): FileUploadResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: uploadFile', [
            'request_id' => $requestId,
            'idempotency_key' => $idempotencyKey,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'contract_id' => $metadata['contract_id'] ?? null,
        ]);

        if ($this->isStubMode()) {
            return FileUploadResponse::fromArray([
                'success' => true,
                'file_id' => 'file_'.Str::random(16),
                'file_url' => 'https://storage.saras.example.com/files/'.Str::random(32).'.jpg',
                'message' => 'File uploaded successfully (stub)',
            ]);
        }

        $response = $this->client()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->attach('file', $file->getContent(), $file->getClientOriginalName())
            ->post('/files/upload', $metadata);

        return FileUploadResponse::fromArray($response->json());
    }

    /**
     * Run AI workflow on an entry.
     */
    public function runAiWorkflow(string $entryId, string $idempotencyKey): AiWorkflowResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: runAiWorkflow', [
            'request_id' => $requestId,
            'idempotency_key' => $idempotencyKey,
            'entry_id' => $entryId,
        ]);

        if ($this->isStubMode()) {
            return AiWorkflowResponse::fromArray([
                'success' => true,
                'workflow_id' => 'workflow_'.Str::random(12),
                'status' => 'processing',
                'results' => null,
                'message' => 'AI workflow initiated successfully (stub)',
            ]);
        }

        $response = $this->client()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post("/entries/{$entryId}/ai-workflow");

        return AiWorkflowResponse::fromArray($response->json());
    }

    /**
     * Get AI workflow status.
     */
    public function getAiWorkflowStatus(string $workflowId): AiWorkflowResponse
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: getAiWorkflowStatus', [
            'request_id' => $requestId,
            'workflow_id' => $workflowId,
        ]);

        if ($this->isStubMode()) {
            return AiWorkflowResponse::fromArray([
                'success' => true,
                'workflow_id' => $workflowId,
                'status' => 'completed',
                'results' => [
                    'analysis' => 'Construction progress is on track. Materials quality verified.',
                    'completion_percentage' => 67.5,
                    'issues_detected' => [],
                    'recommendations' => [
                        'Continue with current pace',
                        'Schedule quality inspection for next phase',
                    ],
                ],
                'message' => 'AI analysis completed (stub)',
            ]);
        }

        $response = $this->client()->get("/ai-workflows/{$workflowId}");

        return AiWorkflowResponse::fromArray($response->json());
    }

    /**
     * Get configured HTTP client.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson();
    }
}
