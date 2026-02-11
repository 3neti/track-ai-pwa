<?php

namespace App\Services\TrackAI;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Upload;
use App\Services\Saras\DTO\EntryResponse;
use App\Services\Saras\DTO\FileUploadResponse;
use App\Services\Saras\SarasClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadService
{
    public function __construct(
        protected SarasClient $sarasClient,
    ) {}

    /**
     * Create an upload record (enqueue for later sync).
     */
    public function createUploadRecord(
        int $userId,
        string $contractId,
        string $title,
        string $documentType,
        string $clientRequestId,
        ?array $tags = null,
        ?string $remarks = null,
        ?string $mime = null,
        ?int $size = null,
    ): Upload {
        // Find project by external_id (contract_id)
        $project = Project::where('external_id', $contractId)->first();

        $upload = Upload::create([
            'project_id' => $project?->id,
            'user_id' => $userId,
            'contract_id' => $contractId,
            'title' => $title,
            'document_type' => $documentType,
            'tags' => $tags ?? [],
            'remarks' => $remarks,
            'mime' => $mime,
            'size' => $size,
            'status' => Upload::STATUS_PENDING,
            'client_request_id' => $clientRequestId,
        ]);

        AuditLog::log($userId, 'upload_enqueued', $contractId, [
            'upload_id' => $upload->id,
            'client_request_id' => $clientRequestId,
            'title' => $title,
            'document_type' => $documentType,
        ]);

        return $upload;
    }

    /**
     * Update upload metadata.
     */
    public function updateMetadata(
        Upload $upload,
        int $userId,
        array $data,
    ): Upload {
        $oldData = $upload->only(['title', 'remarks', 'tags', 'document_type']);

        $upload->update($data);

        AuditLog::log($userId, 'upload_metadata_updated', $upload->contract_id, [
            'upload_id' => $upload->id,
            'old' => $oldData,
            'new' => $data,
        ]);

        return $upload->fresh();
    }

    /**
     * Delete an upload (soft-delete if uploaded, hard-delete if pending).
     */
    public function deleteUpload(
        Upload $upload,
        int $userId,
        ?string $reason = null,
    ): bool {
        $wasPending = $upload->isPending();

        AuditLog::log($userId, 'upload_deleted', $upload->contract_id, [
            'upload_id' => $upload->id,
            'was_pending' => $wasPending,
            'reason' => $reason,
        ]);

        if ($wasPending) {
            // Hard delete - never synced to Saras
            return $upload->forceDelete();
        }

        // Soft delete - mark as deleted
        $upload->update(['status' => Upload::STATUS_DELETED]);

        return $upload->delete();
    }

    /**
     * Retry a failed upload.
     */
    public function retryUpload(Upload $upload, int $userId): Upload
    {
        $upload->update([
            'status' => Upload::STATUS_PENDING,
            'last_error' => null,
        ]);

        AuditLog::log($userId, 'upload_retry', $upload->contract_id, [
            'upload_id' => $upload->id,
        ]);

        return $upload->fresh();
    }

    /**
     * Initialize remote entry in Saras for an Upload record.
     * This creates the entry_id that's needed before file upload.
     */
    public function initRemoteEntry(
        Upload $upload,
        float $latitude = 0,
        float $longitude = 0,
        ?string $ipAddress = null,
    ): Upload {
        // Use client_request_id for deterministic idempotency
        $idempotencyKey = $upload->client_request_id ?? $this->generateIdempotencyKey(
            $upload->user_id,
            $upload->contract_id,
            'upload_init'
        );

        $response = $this->sarasClient->createAnEntry([
            'type' => 'upload',
            'user_id' => $upload->user_id,
            'contract_id' => $upload->contract_id,
            'document_type' => $upload->document_type,
            'tags' => $upload->tags ?? [],
            'name' => $upload->title,
            'remarks' => $upload->remarks,
            'geo_location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'ip_address' => $ipAddress,
            'timestamp' => now()->toIso8601String(),
        ], $idempotencyKey);

        if ($response->success) {
            $upload->update(['entry_id' => $response->entryId]);

            AuditLog::log($upload->user_id, 'upload_entry_created', $upload->contract_id, [
                'upload_id' => $upload->id,
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
            ]);
        } else {
            $upload->update([
                'status' => Upload::STATUS_FAILED,
                'last_error' => $response->message,
            ]);

            AuditLog::log($upload->user_id, 'upload_entry_failed', $upload->contract_id, [
                'upload_id' => $upload->id,
                'error' => $response->message,
            ]);
        }

        return $upload->fresh();
    }

    /**
     * Initialize an upload entry.
     *
     * @deprecated Use createUploadRecord() + initRemoteEntry() instead
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
     * Upload a file to remote storage for an Upload record.
     * Handles entry creation if not already done.
     * Also saves the file locally for preview purposes.
     */
    public function uploadFileToRemote(
        Upload $upload,
        UploadedFile $file,
        float $latitude = 0,
        float $longitude = 0,
        ?string $ipAddress = null,
    ): Upload {
        // Update mime/size immediately so we can save locally
        $upload->update([
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        $upload->refresh(); // Refresh to get updated mime for local path

        // Save file locally for preview (before remote upload)
        $this->saveFileLocally($upload, $file);

        // Create remote entry if not already done
        if (! $upload->entry_id) {
            $upload = $this->initRemoteEntry($upload, $latitude, $longitude, $ipAddress);

            // If entry creation failed, return early
            if ($upload->isFailed()) {
                return $upload;
            }
        }

        // Now upload the file
        $upload->update(['status' => Upload::STATUS_UPLOADING]);

        $idempotencyKey = $upload->client_request_id.'_file';

        $response = $this->sarasClient->uploadFile($file, [
            'entry_id' => $upload->entry_id,
            'contract_id' => $upload->contract_id,
        ], $idempotencyKey);

        if ($response->success) {
            $upload->update([
                'remote_file_id' => $response->fileId,
                'status' => Upload::STATUS_UPLOADED,
            ]);

            AuditLog::log($upload->user_id, 'upload_synced', $upload->contract_id, [
                'upload_id' => $upload->id,
                'entry_id' => $upload->entry_id,
                'file_id' => $response->fileId,
                'idempotency_key' => $idempotencyKey,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);
        } else {
            $upload->update([
                'status' => Upload::STATUS_FAILED,
                'last_error' => $response->message,
            ]);

            AuditLog::log($upload->user_id, 'upload_failed', $upload->contract_id, [
                'upload_id' => $upload->id,
                'entry_id' => $upload->entry_id,
                'error' => $response->message,
            ]);
        }

        return $upload->fresh();
    }

    /**
     * Save an uploaded file locally for preview purposes.
     */
    protected function saveFileLocally(Upload $upload, UploadedFile $file): void
    {
        $directory = Upload::getStorageDirectory();
        $path = $upload->getLocalFilePath();

        if (! $path) {
            return;
        }

        // Ensure directory exists
        Storage::disk('local')->makeDirectory($directory);

        // Save the file
        $file->storeAs($directory, basename($path), 'local');
    }

    /**
     * Upload a file and update the Upload record.
     *
     * @deprecated Use uploadFileToRemote() instead
     */
    public function uploadFile(
        int $userId,
        string $contractId,
        string $entryId,
        UploadedFile $file,
        array $metadata = [],
        ?Upload $upload = null,
    ): FileUploadResponse {
        $idempotencyKey = $upload?->client_request_id ?? $this->generateIdempotencyKey($userId, $contractId, 'file_upload');

        // Mark as uploading if we have an upload record
        if ($upload) {
            $upload->update(['status' => Upload::STATUS_UPLOADING]);
        }

        $response = $this->sarasClient->uploadFile($file, [
            'entry_id' => $entryId,
            'contract_id' => $contractId,
            ...$metadata,
        ], $idempotencyKey);

        if ($response->success) {
            // Update the upload record with Saras response
            if ($upload) {
                $upload->update([
                    'remote_file_id' => $response->fileId,
                    'status' => Upload::STATUS_UPLOADED,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            AuditLog::log($userId, 'upload_synced', $contractId, [
                'upload_id' => $upload?->id,
                'entry_id' => $entryId,
                'file_id' => $response->fileId,
                'idempotency_key' => $idempotencyKey,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);
        } else {
            // Mark as failed if we have an upload record
            if ($upload) {
                $upload->update([
                    'status' => Upload::STATUS_FAILED,
                    'last_error' => $response->message,
                ]);
            }

            AuditLog::log($userId, 'upload_failed', $contractId, [
                'upload_id' => $upload?->id,
                'entry_id' => $entryId,
                'error' => $response->message,
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
