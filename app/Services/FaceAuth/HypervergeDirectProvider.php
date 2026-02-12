<?php

namespace App\Services\FaceAuth;

use App\Contracts\FaceAuthProviderInterface;
use App\Services\FaceAuth\DTO\FaceVerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HypervergeDirectProvider implements FaceAuthProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $appKey,
        private readonly string $verifyPath = '/photo/verifyPair',
        private readonly int $timeout = 30,
    ) {}

    public function verify(string $username, UploadedFile $selfie, string $transactionId): FaceVerificationResult
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'appId' => $this->appId,
                    'appKey' => $this->appKey,
                    'transactionId' => $transactionId,
                ])
                ->attach('selfie', fopen($selfie->getRealPath(), 'r'), $selfie->getClientOriginalName())
                ->post("{$this->baseUrl}{$this->verifyPath}", [
                    'referenceId' => $username,
                ]);

            $data = $response->json();

            if (! $response->successful()) {
                Log::warning('Hyperverge API error', [
                    'status' => $response->status(),
                    'body' => $data,
                    'transactionId' => $transactionId,
                ]);

                return FaceVerificationResult::error(
                    $data['message'] ?? 'Verification service unavailable',
                    $data
                );
            }

            return $this->parseResponse($data);

        } catch (ConnectionException $e) {
            Log::error('Hyperverge connection failed', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);

            return FaceVerificationResult::error('Connection to verification service failed');
        }
    }

    private function parseResponse(array $data): FaceVerificationResult
    {
        $result = $data['result'] ?? [];
        $status = $result['status'] ?? null;

        // Handle quality issues
        if ($status === 'quality_check_failed') {
            $issue = $result['details']['issue'] ?? 'Image quality insufficient';

            return FaceVerificationResult::qualityFailure($issue, $data);
        }

        // Handle not enrolled
        if ($status === 'reference_not_found') {
            return FaceVerificationResult::notEnrolled();
        }

        // Handle match result - requires 'match' key to be present
        if (! array_key_exists('match', $result)) {
            // Unknown response shape - log and return error
            Log::warning('Hyperverge unexpected response shape', [
                'data' => app()->isProduction() ? '[redacted]' : $data,
            ]);

            return FaceVerificationResult::error('Unexpected response from verification service');
        }

        $matched = $result['match'] === true;
        $confidence = $result['confidence'] ?? null;

        if ($matched && $confidence !== null) {
            return FaceVerificationResult::verified((float) $confidence, $data);
        }

        return FaceVerificationResult::notMatched(
            $confidence !== null ? (float) $confidence : null,
            $data
        );
    }
}
