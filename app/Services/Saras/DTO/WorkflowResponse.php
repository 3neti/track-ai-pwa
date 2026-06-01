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
     * Saras live API returns:
     * { "traceId": "...", "runId": { "id": "...", "state": "INITIALISED", ... } }
     *
     * Stub format:
     * { "success": true, "executionId": "...", "status": "completed", ... }
     */
    public static function fromArray(array $data): self
    {
        // Handle Saras live API nested runId format
        $runId = $data['runId'] ?? null;
        $executionId = null;
        $status = $data['status'] ?? 'completed';

        if (is_array($runId) && isset($runId['id'])) {
            $executionId = $runId['id'];
            $status = $runId['state'] ?? $status;
        }

        return new self(
            success: $data['success'] ?? true,
            workflowId: $data['workflowId'] ?? $data['workflow_id'] ?? '',
            executionId: $executionId ?? $data['executionId'] ?? $data['execution_id'] ?? $data['id'] ?? null,
            status: $status,
            result: $data['result'] ?? $data['data'] ?? [],
            message: $data['message'] ?? null,
        );
    }
}
