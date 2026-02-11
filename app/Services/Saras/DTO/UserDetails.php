<?php

namespace App\Services\Saras\DTO;

readonly class UserDetails
{
    public function __construct(
        public string $userId,
        public string $username,
        public string $name,
        public ?string $email,
        public string $role,
        public ?string $department,
        public ?string $region,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            userId: $data['user_id'],
            username: $data['username'],
            name: $data['name'],
            email: $data['email'] ?? null,
            role: $data['role'] ?? 'engineer',
            department: $data['department'] ?? null,
            region: $data['region'] ?? null,
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
