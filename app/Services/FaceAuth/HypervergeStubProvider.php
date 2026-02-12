<?php

namespace App\Services\FaceAuth;

use App\Contracts\FaceAuthProviderInterface;
use App\Services\FaceAuth\DTO\FaceVerificationResult;
use Illuminate\Http\UploadedFile;

class HypervergeStubProvider implements FaceAuthProviderInterface
{
    /**
     * Stub verification for development and testing.
     *
     * Returns verified=true for any valid request.
     * Use special usernames to trigger specific responses:
     * - "fail_match" → returns not_matched
     * - "fail_quality" → returns quality failure
     * - "fail_error" → returns error
     * - "not_enrolled" → returns not enrolled
     */
    public function verify(string $username, UploadedFile $selfie, string $transactionId): FaceVerificationResult
    {
        // Special test cases based on username
        return match ($username) {
            'fail_match' => FaceVerificationResult::notMatched(0.35, $this->stubRaw($transactionId)),
            'fail_quality' => FaceVerificationResult::qualityFailure('Face not clearly visible', $this->stubRaw($transactionId)),
            'fail_error' => FaceVerificationResult::error('Simulated API error', $this->stubRaw($transactionId)),
            'not_enrolled' => FaceVerificationResult::notEnrolled(),
            default => FaceVerificationResult::verified(0.95, $this->stubRaw($transactionId)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function stubRaw(string $transactionId): array
    {
        return [
            'stub' => true,
            'transactionId' => $transactionId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
