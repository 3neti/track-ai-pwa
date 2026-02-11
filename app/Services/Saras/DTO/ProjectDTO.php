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
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: $data['external_id'],
            contractId: $data['contract_id'],
            name: $data['name'],
            description: $data['description'] ?? null,
            status: $data['status'] ?? 'active',
            location: $data['location'] ?? null,
            startDate: $data['start_date'] ?? null,
            endDate: $data['end_date'] ?? null,
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
        ];
    }
}
