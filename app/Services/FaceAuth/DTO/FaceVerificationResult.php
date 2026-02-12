<?php

namespace App\Services\FaceAuth\DTO;

readonly class FaceVerificationResult
{
    public function __construct(
        public bool $verified,
        public ?float $confidence = null,
        public string $reason = 'unknown',
        public array $details = [],
        public array $raw = [],
    ) {}

    public static function verified(float $confidence, array $raw = []): self
    {
        return new self(
            verified: true,
            confidence: $confidence,
            reason: 'matched',
            details: [],
            raw: $raw,
        );
    }

    public static function notMatched(?float $confidence = null, array $raw = []): self
    {
        return new self(
            verified: false,
            confidence: $confidence,
            reason: 'not_matched',
            details: ['message' => 'Face does not match the enrolled reference.'],
            raw: $raw,
        );
    }

    public static function qualityFailure(string $issue, array $raw = []): self
    {
        return new self(
            verified: false,
            confidence: null,
            reason: 'quality',
            details: ['issue' => $issue],
            raw: $raw,
        );
    }

    public static function error(string $message, array $raw = []): self
    {
        return new self(
            verified: false,
            confidence: null,
            reason: 'error',
            details: ['message' => $message],
            raw: $raw,
        );
    }

    public static function notEnrolled(): self
    {
        return new self(
            verified: false,
            confidence: null,
            reason: 'not_enrolled',
            details: ['message' => 'User is not enrolled for face authentication.'],
            raw: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'details' => $this->details,
        ];
    }
}
