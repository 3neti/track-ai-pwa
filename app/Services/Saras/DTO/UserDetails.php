<?php

namespace App\Services\Saras\DTO;

readonly class UserDetails
{
    public function __construct(
        public string $userId,
        public ?string $username,
        public string $name,
        public ?string $email,
        public string $role,
        public ?string $department,
        public ?string $region,
        public ?string $tenantId = null,
        public ?string $tenantName = null,
    ) {}

    /**
     * Create from Saras API response.
     *
     * Saras returns:
     * {
     *   "id": "uuid",
     *   "name": "...",
     *   "email": "...",
     *   "role": "USER",
     *   "tenantId": { "id": "...", "name": "..." }
     * }
     */
    public static function fromArray(array $data): self
    {
        $tenant = $data['tenantId'] ?? [];

        // Handle both Saras live format (id) and stub format (user_id)
        return new self(
            userId: $data['id'] ?? $data['user_id'] ?? '',
            username: $data['username'] ?? $data['email'] ?? null,
            name: $data['name'] ?? 'Unknown',
            email: $data['email'] ?? null,
            role: $data['role'] ?? 'engineer',
            department: $data['department'] ?? null,
            region: $data['region'] ?? (is_array($tenant) ? ($tenant['name'] ?? null) : null),
            tenantId: is_array($tenant) ? ($tenant['id'] ?? null) : null,
            tenantName: is_array($tenant) ? ($tenant['name'] ?? null) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'department' => $this->department,
            'region' => $this->region,
        ];
    }
}
