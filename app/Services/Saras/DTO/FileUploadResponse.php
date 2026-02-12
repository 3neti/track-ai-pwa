<?php

namespace App\Services\Saras\DTO;

readonly class FileUploadResponse
{
    /**
     * @param  array<string>  $fileIds  UUIDs of uploaded files
     */
    public function __construct(
        public bool $success,
        public array $fileIds,
        public ?string $message,
    ) {}

    /**
     * Create from Saras createStorage response.
     *
     * Saras returns array of file objects, each with { id, ... }.
     */
    public static function fromArray(array $data): self
    {
        // Handle both single file and multiple files response
        $files = $data['files'] ?? $data['data'] ?? [];

        // If response is a single file object with 'id'
        if (isset($data['id']) && ! isset($data['files'])) {
            $files = [$data];
        }

        $fileIds = array_filter(array_map(
            fn (array $file) => $file['id'] ?? $file['fileId'] ?? $file['file_id'] ?? null,
            $files
        ));

        return new self(
            success: $data['success'] ?? count($fileIds) > 0,
            fileIds: array_values($fileIds),
            message: $data['message'] ?? null,
        );
    }

    /**
     * Get the first file ID (convenience for single file uploads).
     */
    public function getFirstFileId(): ?string
    {
        return $this->fileIds[0] ?? null;
    }

    /**
     * Legacy accessor for backwards compatibility.
     */
    public function getFileId(): ?string
    {
        return $this->getFirstFileId();
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'file_ids' => $this->fileIds,
            'file_id' => $this->getFirstFileId(), // Legacy compat
            'message' => $this->message,
        ];
    }
}
