<?php

namespace App\Services\Saras\DTO;

readonly class WorkflowRunsResponse
{
    /**
     * @param  array<WorkflowRunDTO>  $runs
     */
    public function __construct(
        public int $page,
        public int $totalCount,
        public int $totalPages,
        public array $runs,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $meta = $data['meta'] ?? [];
        $runs = array_map(
            fn (array $run) => WorkflowRunDTO::fromArray($run),
            $data['runs'] ?? [],
        );

        return new self(
            page: (int) ($meta['page'] ?? 1),
            totalCount: (int) ($meta['totalCount'] ?? 0),
            totalPages: (int) ($meta['totalPages'] ?? 1),
            runs: $runs,
        );
    }

    /**
     * Find a specific run by ID.
     */
    public function findById(string $runId): ?WorkflowRunDTO
    {
        foreach ($this->runs as $run) {
            if ($run->id === $runId) {
                return $run;
            }
        }

        return null;
    }
}
