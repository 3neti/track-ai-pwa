<?php

namespace App\Services\Saras\DTO;

readonly class EntryResponse
{
    public function __construct(
        public bool $success,
        public ?string $entryId,
        public ?string $message,
        public ?string $createdAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            entryId: $data['entry_id'] ?? null,
            message: $data['message'] ?? null,
            createdAt: $data['created_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'entry_id' => $this->entryId,
            'message' => $this->message,
            'created_at' => $this->createdAt,
        ];
    }
}
