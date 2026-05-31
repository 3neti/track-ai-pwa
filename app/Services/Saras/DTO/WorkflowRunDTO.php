<?php

namespace App\Services\Saras\DTO;

readonly class WorkflowRunDTO
{
    public function __construct(
        public string $id,
        public string $state,
        public string $flowState,
        public ?string $createdTs,
        public ?string $updatedTs,
        public ?string $userId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            state: $data['state'] ?? 'UNKNOWN',
            flowState: $data['flowState'] ?? '0.0',
            createdTs: $data['createdTs'] ?? $data['created_ts'] ?? null,
            updatedTs: $data['updatedTs'] ?? $data['updated_ts'] ?? null,
            userId: $data['userId']['id'] ?? $data['user_id'] ?? null,
        );
    }

    public function isSuccess(): bool
    {
        return $this->state === 'SUCCESS';
    }

    public function isFailed(): bool
    {
        return $this->state === 'FAILED';
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, ['SUCCESS', 'FAILED']);
    }

    public function isPending(): bool
    {
        return in_array($this->state, ['INITIALISED', 'WAITING']);
    }
}
