<?php

namespace App\Services\Saras\DTO;

readonly class ProcessResponse
{
    public function __construct(
        public bool $success,
        public ?string $entryId,
        public ?string $processId,
        public ?string $message,
        public ?string $createdAt,
    ) {}

    /**
     * Create from Saras API response.
     *
     * Saras live API returns:
     * { "traceId": "...", "process": { "id": "...", "createdTs": "...", ... } }
     *
     * Stub/legacy format:
     * { "success": true, "id": "...", "entryId": "...", "message": "..." }
     */
    public static function fromArray(array $data): self
    {
        // Handle Saras live API nested response format
        $process = $data['process'] ?? null;
        if ($process && isset($process['id'])) {
            return new self(
                success: true,
                entryId: $process['id'],
                processId: $process['id'],
                message: $data['message'] ?? 'Process created successfully',
                createdAt: $process['createdTs'] ?? null,
            );
        }

        // Handle stub/legacy format
        return new self(
            success: $data['success'] ?? (isset($data['id']) || isset($data['entryId'])),
            entryId: $data['entryId'] ?? $data['entry_id'] ?? $data['id'] ?? null,
            processId: $data['processId'] ?? $data['process_id'] ?? $data['id'] ?? null,
            message: $data['message'] ?? null,
            createdAt: $data['createdAt'] ?? $data['created_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'entry_id' => $this->entryId,
            'process_id' => $this->processId,
            'message' => $this->message,
            'created_at' => $this->createdAt,
        ];
    }
}
