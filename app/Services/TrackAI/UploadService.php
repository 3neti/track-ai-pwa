<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadService
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
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
     * Upload a file to remote storage for an Upload record.
     *
     * New Saras flow:
     * 1. Upload file to Saras storage → get file UUID
     * 2. Create process with file UUID in fields.file
     *
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
        $upload->refresh();

        // Save file locally for preview (before remote upload)
        $this->saveFileLocally($upload, $file);

        // Mark as uploading
        $upload->update(['status' => Upload::STATUS_UPLOADING]);

        try {
            // Step 1: Upload file to Saras storage
            $fileResponse = $this->sarasClient->uploadFiles([$file]);

            if (! $fileResponse->success || ! $fileResponse->getFirstFileId()) {
                throw SarasApiException::uploadFailed(
                    $fileResponse->message ?? 'File upload returned no file ID'
                );
            }

            $remoteFileId = $fileResponse->getFirstFileId();

            // Step 2: Create process entry with file UUID
            $idempotencyKey = $upload->client_request_id ?? $this->generateIdempotencyKey(
                $upload->user_id,
                $upload->contract_id,
                'upload'
            );

            // Resolve contract ID - use default if not provided by Saras yet
            $resolvedContractId = $upload->contract_id ?: config('saras.default_contract_id');

            $processResponse = $this->sarasClient->createProcess(
                subProjectId: config('saras.subproject_ids.trackdata'),
                fields: [
                    'file' => $remoteFileId,
                    'contractId' => $resolvedContractId,
                    'name' => $upload->title,
                    'documentType' => $upload->document_type,
                    'tags' => $upload->tags ?? [],
                    'remarks' => $upload->remarks,
                    'ipAddress' => $ipAddress,
                    'geoLocation' => "{$latitude},{$longitude}",
                    'date' => now()->toDateString(),
                    'time' => now()->toTimeString(),
                ],
                idempotencyKey: $idempotencyKey,
            );

            if (! $processResponse->success) {
                throw SarasApiException::validationError(
                    '/process/createProcess',
                    $processResponse->message ?? 'Failed to create process entry'
                );
            }

            // Success - update upload record
            $upload->update([
                'entry_id' => $processResponse->entryId,
                'remote_file_id' => $remoteFileId,
                'status' => Upload::STATUS_UPLOADED,
            ]);

            AuditLog::log($upload->user_id, 'upload_synced', $upload->contract_id, [
                'upload_id' => $upload->id,
                'entry_id' => $processResponse->entryId,
                'file_id' => $remoteFileId,
                'idempotency_key' => $idempotencyKey,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);

        } catch (SarasApiException $e) {
            $upload->update([
                'status' => Upload::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);

            AuditLog::log($upload->user_id, 'upload_failed', $upload->contract_id, [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'error_type' => $e->type,
            ]);
        }

        return $upload->fresh();
    }

    /**
     * Initialize remote entry in Saras for an Upload record.
     *
     * @deprecated The new Saras flow uploads file first, then creates process.
     *             Use uploadFileToRemote() directly instead.
     */
    public function initRemoteEntry(
        Upload $upload,
        float $latitude = 0,
        float $longitude = 0,
        ?string $ipAddress = null,
    ): Upload {
        // For backwards compatibility, just return the upload as-is
        // The actual entry creation now happens in uploadFileToRemote()
        return $upload;
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
     * Generate idempotency key for upload actions.
     * Used as fallback when client_request_id is not provided.
     * Note: This generates a random suffix, so it's NOT safe for offline replay.
     */
    protected function generateIdempotencyKey(int $userId, string $contractId, string $action): string
    {
        return "upload_{$action}_{$userId}_{$contractId}_".now()->timestamp.'_'.Str::random(8);
    }
}
