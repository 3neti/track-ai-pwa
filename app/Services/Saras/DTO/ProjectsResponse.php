<?php

namespace App\Services\Saras\DTO;

readonly class ProjectsResponse
{
    /**
     * @param  array<ProjectDTO>  $projects
     */
    public function __construct(
        public bool $success,
        public array $projects,
        public int $currentPage,
        public int $totalPages,
        public int $totalCount,
        public ?string $message = null,
    ) {}

    /**
     * Create from Saras API response.
     *
     * Saras returns:
     * {
     *   "meta": { "page": "1", "totalPages": "1", "totalCount": "5" },
     *   "projects": [ ... ]
     * }
     */
    public static function fromArray(array $data): self
    {
        $projectsData = $data['data'] ?? $data['projects'] ?? [];
        $meta = $data['meta'] ?? [];

        return new self(
            success: $data['success'] ?? true,
            projects: array_map(
                fn (array $project) => ProjectDTO::fromArray($project),
                $projectsData
            ),
            currentPage: (int) ($meta['page'] ?? $data['page'] ?? $data['currentPage'] ?? $data['current_page'] ?? 1),
            totalPages: (int) ($meta['totalPages'] ?? $data['totalPages'] ?? $data['total_pages'] ?? 1),
            totalCount: (int) ($meta['totalCount'] ?? $data['totalCount'] ?? $data['total_count'] ?? $data['total'] ?? count($projectsData)),
            message: $data['message'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'projects' => array_map(fn (ProjectDTO $p) => $p->toArray(), $this->projects),
            'current_page' => $this->currentPage,
            'total_pages' => $this->totalPages,
            'total_count' => $this->totalCount,
            'message' => $this->message,
        ];
    }
}
