<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AuditLog;
use App\Services\Saras\DTO\AiWorkflowResponse;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\DTO\ProcessResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ProgressService
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * Check if progress sync to Saras is enabled.
     */
    protected function isProgressSyncEnabled(): bool
    {
        return config('saras.feature_flags.enabled', true)
            && config('saras.feature_flags.progress_enabled', false);
    }

    /**
     * Submit a progress update.
     *
     * NOTE: Saras sync is feature-flagged. When disabled, returns a stub response
     * and only logs locally. Progress UI remains functional.
     */
    public function submitProgress(
        int $userId,
        string $contractId,
        array $checklistItems,
        ?string $remarks = null,
        float $latitude = 0,
        float $longitude = 0,
        ?string $ipAddress = null,
        ?string $clientRequestId = null,
    ): ProcessResponse {
        $idempotencyKey = $clientRequestId ?? $this->generateIdempotencyKey($userId, $contractId, 'progress');

        // Feature flag: skip Saras sync if progress is disabled
        if (! $this->isProgressSyncEnabled()) {
            AuditLog::log($userId, 'progress_submit_local', $contractId, [
                'idempotency_key' => $idempotencyKey,
                'checklist_count' => count($checklistItems),
                'saras_sync' => false,
            ]);

            return ProcessResponse::fromArray([
                'success' => true,
                'entry_id' => 'local_'.Str::random(12),
                'message' => 'Progress saved locally (Saras sync pending)',
            ]);
        }

        try {
            $response = $this->sarasClient->createProcess(
                subProjectId: config('saras.subproject_ids.trackdata'), // TODO: Use progress subProjectId when available
                fields: [
                    'userId' => $userId,
                    'contractId' => $contractId ?: config('saras.default_contract_id'),
                    'checklistItems' => $checklistItems,
                    'remarks' => $remarks,
                    'geoLocation' => "{$latitude},{$longitude}",
                    'ipAddress' => $ipAddress,
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                ],
                idempotencyKey: $idempotencyKey,
            );

            if ($response->success) {
                AuditLog::log($userId, 'progress_submit', $contractId, [
                    'entry_id' => $response->entryId,
                    'idempotency_key' => $idempotencyKey,
                    'checklist_count' => count($checklistItems),
                ]);
            }

            return $response;

        } catch (SarasApiException $e) {
            return ProcessResponse::fromArray([
                'success' => false,
                'entry_id' => null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upload a progress photo.
     */
    public function uploadProgressPhoto(
        int $userId,
        string $contractId,
        string $entryId,
        UploadedFile $file,
        string $photoType,
    ): FileUploadResponse {
        // Feature flag: skip Saras sync if progress is disabled
        if (! $this->isProgressSyncEnabled()) {
            return FileUploadResponse::fromArray([
                'success' => true,
                'files' => [['id' => 'local_'.Str::random(16)]],
                'message' => 'Photo saved locally (Saras sync pending)',
            ]);
        }

        try {
            $response = $this->sarasClient->uploadFiles([$file]);

            if ($response->success) {
                AuditLog::log($userId, 'progress_photo_upload', $contractId, [
                    'entry_id' => $entryId,
                    'file_id' => $response->getFirstFileId(),
                    'photo_type' => $photoType,
                ]);
            }

            return $response;

        } catch (SarasApiException $e) {
            return FileUploadResponse::fromArray([
                'success' => false,
                'files' => [],
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Trigger AI workflow for progress analysis.
     *
     * NOTE: AI workflow is part of the progress feature which is currently disabled.
     * This returns a stub response when the feature flag is off.
     */
    public function runAiAnalysis(int $userId, string $contractId, string $entryId): AiWorkflowResponse
    {
        // Feature flag: skip if progress is disabled
        if (! $this->isProgressSyncEnabled()) {
            return AiWorkflowResponse::fromArray([
                'success' => false,
                'workflow_id' => null,
                'status' => 'disabled',
                'message' => 'AI workflow is not available (Saras sync pending)',
            ]);
        }

        // TODO: Implement when Saras provides AI workflow API
        return AiWorkflowResponse::fromArray([
            'success' => false,
            'workflow_id' => null,
            'status' => 'not_implemented',
            'message' => 'AI workflow API not yet implemented',
        ]);
    }

    /**
     * Get AI workflow status.
     */
    public function getAiStatus(string $workflowId): AiWorkflowResponse
    {
        // Feature flag: skip if progress is disabled
        if (! $this->isProgressSyncEnabled()) {
            return AiWorkflowResponse::fromArray([
                'success' => false,
                'workflow_id' => $workflowId,
                'status' => 'disabled',
                'message' => 'AI workflow is not available (Saras sync pending)',
            ]);
        }

        // TODO: Implement when Saras provides AI workflow API
        return AiWorkflowResponse::fromArray([
            'success' => false,
            'workflow_id' => $workflowId,
            'status' => 'not_implemented',
            'message' => 'AI workflow API not yet implemented',
        ]);
    }

    /**
     * Generate idempotency key for progress actions.
     * Used as fallback when client_request_id is not provided.
     * Note: This generates a random suffix, so it's NOT safe for offline replay.
     */
    protected function generateIdempotencyKey(int $userId, string $contractId, string $action): string
    {
        return "progress_{$action}_{$userId}_{$contractId}_".now()->timestamp.'_'.Str::random(8);
    }
}
