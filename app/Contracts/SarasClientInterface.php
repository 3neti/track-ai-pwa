<?php

namespace App\Contracts;

use App\Exceptions\SarasApiException;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\ProcessResponse;
use App\Services\Saras\DTO\ProjectsResponse;
use App\Services\Saras\DTO\UserDetails;
use App\Services\Saras\DTO\WorkflowResponse;
use App\Services\Saras\DTO\WorkflowRunsResponse;
use Illuminate\Http\UploadedFile;

interface SarasClientInterface
{
    /**
     * Check if running in stub mode.
     */
    public function isStubMode(): bool;

    /**
     * Get user details for the authenticated service account.
     *
     * @throws SarasApiException
     */
    public function getUserDetails(): UserDetails;

    /**
     * Get projects assigned to a user with pagination.
     *
     * @throws SarasApiException
     */
    public function getProjectsForUser(int $page = 1, int $perPage = 10): ProjectsResponse;

    /**
     * Create a process entry in a subproject.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SarasApiException
     */
    public function createProcess(
        string $subProjectId,
        array $fields,
        ?string $idempotencyKey = null,
        ?string $parentProcessId = null,
        ?string $processTitle = null,
    ): ProcessResponse;

    /**
     * Upload files to Saras storage.
     *
     * @param  array<UploadedFile>  $files
     *
     * @throws SarasApiException
     */
    public function uploadFiles(array $files, string $subProjectId): FileUploadResponse;

    /**
     * Execute an AI workflow for image analysis.
     *
     * @param  array<string, mixed>  $otherDetails
     * @param  array<string, mixed>  $payload
     *
     * @throws SarasApiException
     */
    public function executeWorkflow(?string $workflowId = null, array $otherDetails = [], array $payload = []): WorkflowResponse;

    /**
     * Get workflow runs with optional Saras query parameters.
     *
     * @param  array<string, string>  $filters  Supported: subProjectId, stageKey, processId, workflowId, runId.
     *
     * @throws SarasApiException
     */
    public function getWorkflowRuns(int $page = 1, int $perPage = 10, array $filters = []): WorkflowRunsResponse;

    /**
     * Attach files to a workflow stage checklist.
     *
     * @param  array<string, array{fileIds: array<string>}>  $files
     * @return array<string, mixed>
     *
     * @throws SarasApiException
     */
    public function updateFiles(string $processId, string $stageKey, string $subProjectId, array $files): array;

    /**
     * List processes (entries) under a subproject.
     *
     * @return array<string, mixed>
     *
     * @throws SarasApiException
     */
    public function getProcesses(string $subProjectId, int $page = 1, int $perPage = 10): array;

    /**
     * Update fields on an existing process.
     *
     * @param  array<string, mixed>  $updates  Key-value map of fields to update
     * @return array<string, mixed>
     *
     * @throws SarasApiException
     */
    public function updateProcessField(string $processId, string $subProjectId, array $updates): array;

    /**
     * Get a temporary download URL for a file in a subproject.
     *
     * @return array<string, mixed>
     *
     * @throws SarasApiException
     */
    public function getFileUrl(string $subProjectId, string $fileId): array;
}
