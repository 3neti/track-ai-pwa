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
        public array $rawData = [],
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
            rawData: $data,
        );
    }

    /**
     * Scan raw response for diagnostic fields that might contain failure details.
     *
     * @return array<string, mixed>
     */
    public function diagnosticFields(): array
    {
        $candidates = [
            'error', 'errors', 'message', 'errorMessage', 'failureReason', 'reason',
            'result', 'results', 'output', 'outputs', 'payload', 'logs',
            'nodeResults', 'steps', 'tasks', 'artifacts', 'files',
            'certificate', 'certificateOfCompletion',
        ];

        $found = [];

        foreach ($candidates as $key) {
            if (array_key_exists($key, $this->rawData) && $this->rawData[$key] !== null) {
                $found[$key] = $this->rawData[$key];
            }
        }

        return $found;
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
