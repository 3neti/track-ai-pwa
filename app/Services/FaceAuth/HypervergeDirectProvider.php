<?php

namespace App\Services\FaceAuth;

use App\Contracts\FaceAuthProviderInterface;
use App\Models\User;
use App\Services\FaceAuth\DTO\FaceVerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HypervergeDirectProvider implements FaceAuthProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $appKey,
        private readonly string $livenessPath = '/checkLiveness',
        private readonly string $matchPath = '/matchFace',
        private readonly string $matchType = 'face_face',
        private readonly float $confidenceThreshold = 85,
        private readonly int $timeout = 30,
    ) {}

    public function verify(string $username, UploadedFile $selfie, string $transactionId): FaceVerificationResult
    {
        try {
            if ($this->appId === '' || $this->appKey === '') {
                return FaceVerificationResult::error('Face verification service is not configured.');
            }

            $referenceImagePath = $this->referenceImagePath($username);

            if ($referenceImagePath === null) {
                return FaceVerificationResult::notEnrolled();
            }

            $liveness = $this->checkLiveness($selfie, $transactionId);

            if (! $liveness->successful()) {
                return $this->apiError('Hyperverge liveness API error', $liveness, $transactionId);
            }

            $livenessData = $liveness->json() ?? [];
            $livenessResult = $this->parseLivenessResponse($livenessData);

            if (! $livenessResult->verified) {
                return $livenessResult;
            }

            $match = $this->matchFace($selfie, $referenceImagePath, $transactionId);

            if (! $match->successful()) {
                return $this->apiError('Hyperverge face match API error', $match, $transactionId);
            }

            return $this->parseMatchResponse($match->json() ?? []);

        } catch (ConnectionException $e) {
            Log::error('Hyperverge connection failed', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);

            return FaceVerificationResult::error('Connection to verification service failed');
        } catch (Throwable $e) {
            Log::error('Hyperverge verification failed', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);

            return FaceVerificationResult::error('Face verification failed.');
        }
    }

    private function referenceImagePath(string $username): ?string
    {
        $user = User::query()
            ->where('username', $username)
            ->orWhere('email', $username)
            ->with('activeHypervergeFaceEnrollment')
            ->first();

        $enrollment = $user?->activeHypervergeFaceEnrollment;

        if ($enrollment === null || ! Storage::disk($enrollment->disk)->exists($enrollment->path)) {
            return null;
        }

        return Storage::disk($enrollment->disk)->path($enrollment->path);
    }

    private function checkLiveness(UploadedFile $selfie, string $transactionId): Response
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(10)
            ->withHeaders($this->headers($transactionId))
            ->attach('image', fopen($selfie->getRealPath(), 'r'), $selfie->getClientOriginalName())
            ->post($this->endpoint($this->livenessPath));
    }

    private function matchFace(UploadedFile $selfie, string $referenceImagePath, string $transactionId): Response
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(10)
            ->withHeaders($this->headers($transactionId))
            ->attach('selfie', fopen($selfie->getRealPath(), 'r'), $selfie->getClientOriginalName())
            ->attach('selfie2', fopen($referenceImagePath, 'r'), basename($referenceImagePath))
            ->post($this->endpoint($this->matchPath), [
                'type' => $this->matchType,
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $transactionId): array
    {
        return [
            'appId' => $this->appId,
            'appKey' => $this->appKey,
            'transactionId' => $transactionId,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function apiError(string $message, Response $response, string $transactionId): FaceVerificationResult
    {
        $data = $response->json() ?? [];

        Log::warning($message, [
            'status' => $response->status(),
            'body' => app()->isProduction() ? '[redacted]' : $data,
            'transactionId' => $transactionId,
        ]);

        return FaceVerificationResult::error(
            data_get($data, 'result.error') ?? $data['message'] ?? 'Verification service unavailable',
            $data
        );
    }

    private function parseLivenessResponse(array $data): FaceVerificationResult
    {
        $liveValue = data_get($data, 'result.details.liveFace.value');
        $action = data_get($data, 'result.summary.action');

        if ($liveValue === 'yes' && $action === 'pass') {
            return FaceVerificationResult::verified(100, $data);
        }

        return FaceVerificationResult::qualityFailure($this->failureMessage($data), $data);
    }

    private function parseMatchResponse(array $data): FaceVerificationResult
    {
        $matchValue = data_get($data, 'result.details.match.value');
        $action = data_get($data, 'result.summary.action');
        $confidence = $this->confidenceScore(data_get($data, 'result.details.match.confidence'));

        if ($matchValue === 'yes' && $action === 'pass' && $confidence >= $this->confidenceThreshold) {
            return FaceVerificationResult::verified($confidence, $data);
        }

        return FaceVerificationResult::notMatched($confidence, $data);
    }

    private function confidenceScore(mixed $confidence): float
    {
        if (is_numeric($confidence)) {
            return (float) $confidence;
        }

        return match ($confidence) {
            'very_high' => 95,
            'high' => 85,
            'medium' => 60,
            'low' => 30,
            default => 0,
        };
    }

    private function failureMessage(array $data): string
    {
        $summaryDetails = collect(data_get($data, 'result.summary.details', []))
            ->pluck('message')
            ->filter()
            ->values();

        if ($summaryDetails->isNotEmpty()) {
            return $summaryDetails->implode(' ');
        }

        $qualityIssues = collect(data_get($data, 'result.details.qualityChecks', []))
            ->filter(fn (mixed $check): bool => is_array($check) && ($check['value'] ?? null) === 'yes')
            ->keys()
            ->values();

        if ($qualityIssues->isNotEmpty()) {
            return 'Image quality issue: '.$qualityIssues->implode(', ');
        }

        return 'Liveness check failed.';
    }
}
