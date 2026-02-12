<?php

namespace App\Services\Saras;

use App\Contracts\SarasClientInterface;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\ProcessResponse;
use App\Services\Saras\DTO\ProjectsResponse;
use App\Services\Saras\DTO\UserDetails;
use App\Services\Saras\DTO\WorkflowResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SarasStubClient implements SarasClientInterface
{
    public function isStubMode(): bool
    {
        return true;
    }

    public function getUserDetails(): UserDetails
    {
        return UserDetails::fromArray([
            'user_id' => 'stub_user_'.Str::random(8),
            'username' => 'engineer_stub',
            'name' => 'Juan Dela Cruz',
            'email' => 'engineer@dpwh.gov.ph',
            'role' => 'engineer',
            'department' => 'DPWH Region III',
            'region' => 'Central Luzon',
        ]);
    }

    public function getProjectsForUser(int $page = 1, int $perPage = 10): ProjectsResponse
    {
        $allProjects = [
            [
                'external_id' => 'PROJ-2024-001',
                'contract_id' => 'CONTRACT-R3-2024-0156',
                'name' => 'Rehabilitation of National Road Section - Bulacan',
                'description' => 'Rehabilitation and improvement of 5.2km national road section.',
                'status' => 'active',
                'location' => 'Bulacan, Region III',
                'start_date' => '2024-01-15',
                'end_date' => '2024-12-31',
            ],
            [
                'external_id' => 'PROJ-2024-002',
                'contract_id' => 'CONTRACT-R3-2024-0189',
                'name' => 'Bridge Construction - Pampanga River',
                'description' => 'Construction of new 120-meter bridge crossing Pampanga River.',
                'status' => 'active',
                'location' => 'Pampanga, Region III',
                'start_date' => '2024-03-01',
                'end_date' => '2025-06-30',
            ],
            [
                'external_id' => 'PROJ-2024-003',
                'contract_id' => 'CONTRACT-R3-2024-0201',
                'name' => 'Flood Control Project - Nueva Ecija',
                'description' => 'Implementation of flood mitigation infrastructure.',
                'status' => 'active',
                'location' => 'Nueva Ecija, Region III',
                'start_date' => '2024-02-01',
                'end_date' => '2024-11-30',
            ],
        ];

        // Simulate pagination
        $total = count($allProjects);
        $offset = ($page - 1) * $perPage;
        $pageProjects = array_slice($allProjects, $offset, $perPage);

        return ProjectsResponse::fromArray([
            'success' => true,
            'data' => $pageProjects,
            'page' => $page,
            'totalPages' => (int) ceil($total / $perPage),
            'totalCount' => $total,
        ]);
    }

    public function createProcess(string $subProjectId, array $fields, ?string $idempotencyKey = null): ProcessResponse
    {
        $entryId = 'entry_'.Str::random(12);

        return ProcessResponse::fromArray([
            'success' => true,
            'id' => $entryId,
            'entryId' => $entryId,
            'processId' => 'process_'.Str::random(12),
            'message' => 'Process created successfully (stub)',
            'createdAt' => now()->toIso8601String(),
        ]);
    }

    public function uploadFiles(array $files): FileUploadResponse
    {
        $uploadedFiles = [];

        foreach ($files as $file) {
            /** @var UploadedFile $file */
            $uploadedFiles[] = [
                'id' => Str::uuid()->toString(),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mimeType' => $file->getMimeType(),
            ];
        }

        return FileUploadResponse::fromArray([
            'success' => true,
            'files' => $uploadedFiles,
            'message' => 'Files uploaded successfully (stub)',
        ]);
    }

    public function executeWorkflow(?string $workflowId = null, array $otherDetails = [], array $payload = []): WorkflowResponse
    {
        $workflowId = $workflowId ?? config('saras.workflow_id');

        return WorkflowResponse::fromArray([
            'success' => true,
            'workflowId' => $workflowId,
            'executionId' => 'exec_'.Str::random(12),
            'status' => 'completed',
            'result' => [
                'analysis' => 'AI analysis completed successfully (stub)',
                'confidence' => 0.95,
                'tags' => ['construction', 'progress', 'site'],
            ],
            'message' => 'Workflow executed successfully (stub)',
        ]);
    }
}
