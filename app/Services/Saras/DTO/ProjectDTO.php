<?php

namespace App\Services\Saras\DTO;

readonly class ProjectDTO
{
    public function __construct(
        public string $externalId,
        public string $contractId,
        public string $name,
        public ?string $description,
        public string $status,
        public ?string $location,
        public ?string $startDate,
        public ?string $endDate,
        public ?string $tenantId = null,
        public ?string $tenantName = null,
    ) {}

    /**
     * Create from Saras API response.
     *
     * Saras returns:
     * {
     *   "id": "uuid",
     *   "projectMeta": { "projectId": "...", "name": "..." },
     *   "tenantId": { "id": "...", "name": "..." }
     * }
     *
     * Or stub format:
     * { "external_id": "...", "contract_id": "...", "name": "..." }
     */
    public static function fromArray(array $data): self
    {
        // Handle actual Saras API response format
        if (isset($data['projectMeta'])) {
            $meta = $data['projectMeta'];
            $tenant = $data['tenantId'] ?? [];

            return new self(
                externalId: $data['id'] ?? '',
                contractId: $meta['projectId'] ?? $data['id'] ?? '',
                name: $meta['name'] ?? 'Unknown Project',
                description: $meta['description'] ?? null,
                status: $meta['status'] ?? 'active',
                location: $meta['location'] ?? null,
                startDate: $data['createdTs'] ?? null,
                endDate: null,
                tenantId: $tenant['id'] ?? null,
                tenantName: $tenant['name'] ?? null,
            );
        }

        // Handle stub/legacy format
        return new self(
            externalId: $data['external_id'] ?? $data['id'] ?? '',
            contractId: $data['contract_id'] ?? $data['id'] ?? '',
            name: $data['name'] ?? 'Unknown Project',
            description: $data['description'] ?? null,
            status: $data['status'] ?? 'active',
            location: $data['location'] ?? null,
            startDate: $data['start_date'] ?? $data['createdTs'] ?? null,
            endDate: $data['end_date'] ?? null,
            tenantId: null,
            tenantName: null,
        );
    }

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'contract_id' => $this->contractId,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'location' => $this->location,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'tenant_id' => $this->tenantId,
            'tenant_name' => $this->tenantName,
        ];
    }
}
