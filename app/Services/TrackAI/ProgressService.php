<?php

namespace App\Services\TrackAI;

use App\Models\AuditLog;
use App\Services\Saras\DTO\AiWorkflowResponse;
use App\Services\Saras\DTO\EntryResponse;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\SarasClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ProgressService
{
    public function __construct(
        protected SarasClient $sarasClient,
    ) {}

    /**
     * Submit a progress update.
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
    ): EntryResponse {
        // Use client_request_id for deterministic idempotency (offline replay safe)
        $idempotencyKey = $clientRequestId ?? $this->generateIdempotencyKey($userId, $contractId, 'progress');

        $response = $this->sarasClient->createAnEntry([
            'type' => 'progress_update',
            'user_id' => $userId,
            'contract_id' => $contractId,
            'checklist_items' => $checklistItems,
            'remarks' => $remarks,
            'geo_location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'ip_address' => $ipAddress,
            'timestamp' => now()->toIso8601String(),
        ], $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'progress_submit', $contractId, [
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
                'checklist_count' => count($checklistItems),
            ]);
        }

        return $response;
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
        $idempotencyKey = $this->generateIdempotencyKey($userId, $contractId, 'progress_photo');

        $response = $this->sarasClient->uploadFile($file, [
            'entry_id' => $entryId,
            'contract_id' => $contractId,
            'photo_type' => $photoType,
        ], $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'progress_photo_upload', $contractId, [
                'entry_id' => $entryId,
                'file_id' => $response->fileId,
                'photo_type' => $photoType,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        return $response;
    }

    /**
     * Trigger AI workflow for progress analysis.
     */
    public function runAiAnalysis(int $userId, string $contractId, string $entryId): AiWorkflowResponse
    {
        $idempotencyKey = $this->generateIdempotencyKey($userId, $contractId, 'ai_workflow');

        $response = $this->sarasClient->runAiWorkflow($entryId, $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'ai_workflow_triggered', $contractId, [
                'entry_id' => $entryId,
                'workflow_id' => $response->workflowId,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        return $response;
    }

    /**
     * Get AI workflow status.
     */
    public function getAiStatus(string $workflowId): AiWorkflowResponse
    {
        return $this->sarasClient->getAiWorkflowStatus($workflowId);
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
