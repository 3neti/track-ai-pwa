<?php

namespace App\Contracts;

use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\ProcessResponse;
use App\Services\Saras\DTO\ProjectsResponse;
use App\Services\Saras\DTO\UserDetails;
use App\Services\Saras\DTO\WorkflowResponse;
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
     * @throws \App\Exceptions\SarasApiException
     */
    public function getUserDetails(): UserDetails;

    /**
     * Get projects assigned to a user with pagination.
     *
     * @throws \App\Exceptions\SarasApiException
     */
    public function getProjectsForUser(int $page = 1, int $perPage = 10): ProjectsResponse;

    /**
     * Create a process entry in a subproject.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws \App\Exceptions\SarasApiException
     */
    public function createProcess(string $subProjectId, array $fields, ?string $idempotencyKey = null): ProcessResponse;

    /**
     * Upload files to Saras storage.
     *
     * @param  array<UploadedFile>  $files
     *
     * @throws \App\Exceptions\SarasApiException
     */
    public function uploadFiles(array $files): FileUploadResponse;

    /**
     * Execute an AI workflow for image analysis.
     *
     * @param  array<string, mixed>  $otherDetails
     * @param  array<string, mixed>  $payload
     *
     * @throws \App\Exceptions\SarasApiException
     */
    public function executeWorkflow(?string $workflowId = null, array $otherDetails = [], array $payload = []): WorkflowResponse;
}
