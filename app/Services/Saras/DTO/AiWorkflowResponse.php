<?php

namespace App\Services\Saras\DTO;

readonly class AiWorkflowResponse
{
    public function __construct(
        public bool $success,
        public ?string $workflowId,
        public string $status,
        public ?array $results,
        public ?string $message,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            workflowId: $data['workflow_id'] ?? null,
            status: $data['status'] ?? 'pending',
            results: $data['results'] ?? null,
            message: $data['message'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'workflow_id' => $this->workflowId,
            'status' => $this->status,
            'results' => $this->results,
            'message' => $this->message,
        ];
    }
}
