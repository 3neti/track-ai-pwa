<?php

namespace App\Services\Saras\DTO;

readonly class LoginResponse
{
    public function __construct(
        public bool $success,
        public ?string $token,
        public ?string $userId,
        public ?string $message,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            token: $data['token'] ?? null,
            userId: $data['user_id'] ?? null,
            message: $data['message'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'token' => $this->token,
            'user_id' => $this->userId,
            'message' => $this->message,
        ];
    }
}
