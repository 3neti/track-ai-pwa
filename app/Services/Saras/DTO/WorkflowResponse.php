<?php

namespace App\Services\Saras\DTO;

readonly class WorkflowResponse
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public bool $success,
        public string $workflowId,
        public ?string $executionId,
        public string $status,
        public array $result = [],
        public ?string $message = null,
    ) {}

    /**
     * Create from API response array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? true,
            workflowId: $data['workflowId'] ?? $data['workflow_id'] ?? '',
            executionId: $data['executionId'] ?? $data['execution_id'] ?? $data['id'] ?? null,
            status: $data['status'] ?? 'completed',
            result: $data['result'] ?? $data['data'] ?? [],
            message: $data['message'] ?? null,
        );
    }
}
