<?php

namespace App\Services\TrackAI;

use App\Models\AuditLog;
use App\Services\Saras\DTO\EntryResponse;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\SarasClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UploadService
{
    public function __construct(
        protected SarasClient $sarasClient,
    ) {}

    /**
     * Initialize an upload entry.
     */
    public function initUpload(
        int $userId,
        string $contractId,
        string $documentType,
        array $tags,
        ?string $name = null,
        ?string $remarks = null,
        float $latitude = 0,
        float $longitude = 0,
        ?string $ipAddress = null,
        ?string $clientRequestId = null,
    ): EntryResponse {
        // Use client_request_id for deterministic idempotency (offline replay safe)
        $idempotencyKey = $clientRequestId ?? $this->generateIdempotencyKey($userId, $contractId, 'upload_init');

        $response = $this->sarasClient->createAnEntry([
            'type' => 'upload',
            'user_id' => $userId,
            'contract_id' => $contractId,
            'document_type' => $documentType,
            'tags' => $tags,
            'name' => $name,
            'remarks' => $remarks,
            'geo_location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'ip_address' => $ipAddress,
            'timestamp' => now()->toIso8601String(),
        ], $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'upload_init', $contractId, [
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => $documentType,
                'tags' => $tags,
            ]);
        }

        return $response;
    }

    /**
     * Upload a file.
     */
    public function uploadFile(
        int $userId,
        string $contractId,
        string $entryId,
        UploadedFile $file,
        array $metadata = [],
    ): FileUploadResponse {
        $idempotencyKey = $this->generateIdempotencyKey($userId, $contractId, 'file_upload');

        $response = $this->sarasClient->uploadFile($file, [
            'entry_id' => $entryId,
            'contract_id' => $contractId,
            ...$metadata,
        ], $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'file_upload', $contractId, [
                'entry_id' => $entryId,
                'file_id' => $response->fileId,
                'idempotency_key' => $idempotencyKey,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);
        }

        return $response;
    }

    /**
     * Generate idempotency key for upload actions.
     * Used as fallback when client_request_id is not provided.
     * Note: This generates a random suffix, so it's NOT safe for offline replay.
     */
    protected function generateIdempotencyKey(int $userId, string $contractId, string $action): string
    {
        return "upload_{$action}_{$userId}_{$contractId}_".now()->timestamp.'_'.Str::random(8);
    }
}
