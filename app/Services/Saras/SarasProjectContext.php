<?php

namespace App\Services\Saras;

readonly class SarasProjectContext
{
    /**
     * @param  array<string, ?string>  $subprojectIds
     * @param  array<string, string>  $subprojectSources
     * @param  array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}  $branding
     * @param  array<string, mixed>  $rawProject
     */
    public function __construct(
        public ?string $projectId,
        public ?string $projectName,
        public string $source,
        public array $subprojectIds,
        public array $subprojectSources,
        public array $branding,
        public array $rawProject = [],
        public ?string $message = null,
    ) {}

    public function subProjectId(string $key, ?string $fallbackKey = null): ?string
    {
        $value = $this->subprojectIds[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if ($fallbackKey) {
            $fallback = $this->subprojectIds[$fallbackKey] ?? null;

            return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'source' => $this->source,
            'subproject_ids' => $this->subprojectIds,
            'subproject_sources' => $this->subprojectSources,
            'branding' => $this->branding,
            'message' => $this->message,
        ];
    }
}
