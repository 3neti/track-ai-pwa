<?php

namespace App\Services\Saras\DTO;

readonly class FileUploadResponse
{
    public function __construct(
        public bool $success,
        public ?string $fileId,
        public ?string $fileUrl,
        public ?string $message,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            fileId: $data['file_id'] ?? null,
            fileUrl: $data['file_url'] ?? null,
            message: $data['message'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'file_id' => $this->fileId,
            'file_url' => $this->fileUrl,
            'message' => $this->message,
        ];
    }
}
