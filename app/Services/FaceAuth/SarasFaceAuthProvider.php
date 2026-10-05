<?php

namespace App\Services\FaceAuth;

use App\Contracts\FaceAuthProviderInterface;
use App\Services\FaceAuth\DTO\FaceVerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SarasFaceAuthProvider implements FaceAuthProviderInterface
{
    private const ENDPOINT_LABEL = 'loginWithFace';

    private const BACKEND_BLOCKED_MESSAGE = 'Saras could not accept the backend request. Please contact support.';

    private const BIOMETRIC_MISMATCH_MESSAGE = 'Face verification failed. Please try again.';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $loginPath,
        private readonly int $timeout = 30,
    ) {}

    public function verify(string $username, UploadedFile $selfie, string $transactionId): FaceVerificationResult
    {
        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(10)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint($this->loginPath), [
                    'client_id' => $username,
                    'face' => $this->base64Image($selfie),
                ]);

            if (! $response->successful()) {
                return $this->apiError($response, $transactionId);
            }

            return $this->parseLoginResponse($response->json() ?? []);
        } catch (ConnectionException $e) {
            Log::error('Saras face login connection failed', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);

            return FaceVerificationResult::failed(
                'saras_connection_failed',
                'Connection to Saras face login failed.',
                details: [
                    'failure_type' => 'saras_connection_failed',
                    'endpoint' => self::ENDPOINT_LABEL,
                ],
            );
        } catch (Throwable $e) {
            Log::error('Saras face login failed', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);

            return FaceVerificationResult::failed(
                'saras_face_login_failed',
                'Saras face login failed.',
                details: [
                    'failure_type' => 'saras_face_login_failed',
                    'endpoint' => self::ENDPOINT_LABEL,
                ],
            );
        }
    }

    private function parseLoginResponse(array $data): FaceVerificationResult
    {
        $token = $data['access_token'] ?? $data['token'] ?? null;
        $success = (bool) ($data['success'] ?? $data['verified'] ?? $data['authenticated'] ?? filled($token));
        $confidence = $this->confidence($data);

        if ($success) {
            return FaceVerificationResult::verified($confidence, $data, [
                'access_token' => $token,
                'expires_in' => $data['expires_in'] ?? $data['expiresIn'] ?? 3600,
                'user' => $data['user'] ?? $data['userDetails'] ?? null,
            ]);
        }

        return FaceVerificationResult::notMatched($confidence, $data);
    }

    private function apiError(Response $response, string $transactionId): FaceVerificationResult
    {
        $data = $response->json() ?? [];
        $responseType = $this->responseType($response);
        $message = $this->errorMessage($response, $data, $responseType);
        $errorCode = data_get($data, 'errorCode');

        Log::warning('Saras face login API error', [
            'status' => $response->status(),
            'response_type' => $responseType,
            'error_code' => $errorCode,
            'body' => app()->isProduction() ? '[redacted]' : $this->loggableBody($response, $data, $responseType),
            'transactionId' => $transactionId,
        ]);

        if ($this->isWafBlocked($response, $responseType)) {
            return FaceVerificationResult::failed(
                'saras_waf_blocked',
                self::BACKEND_BLOCKED_MESSAGE,
                details: [
                    'status' => $response->status(),
                    'failure_type' => 'saras_waf_blocked',
                    'response_type' => $responseType,
                    'endpoint' => self::ENDPOINT_LABEL,
                ],
            );
        }

        if ($responseType !== 'json') {
            return FaceVerificationResult::failed(
                'saras_non_json_response',
                self::BACKEND_BLOCKED_MESSAGE,
                details: [
                    'status' => $response->status(),
                    'failure_type' => 'saras_non_json_response',
                    'response_type' => $responseType,
                    'endpoint' => self::ENDPOINT_LABEL,
                ],
            );
        }

        if ((string) $errorCode === '1509') {
            return FaceVerificationResult::notEnrolled(
                $data,
                [
                    'message' => $message,
                    'status' => $response->status(),
                    'error_code' => $errorCode,
                    'failure_type' => 'saras_face_not_registered',
                    'response_type' => $responseType,
                    'endpoint' => self::ENDPOINT_LABEL,
                    'registration_required' => true,
                ],
            );
        }

        if ((string) $errorCode === '1510') {
            return FaceVerificationResult::notMatched(
                raw: $data,
                details: [
                    'message' => self::BIOMETRIC_MISMATCH_MESSAGE,
                    'status' => $response->status(),
                    'error_code' => $errorCode,
                    'failure_type' => 'saras_face_mismatch',
                    'response_type' => $responseType,
                    'endpoint' => self::ENDPOINT_LABEL,
                ],
            );
        }

        return FaceVerificationResult::failed(
            'saras_api_error',
            $message,
            $data,
            [
                'status' => $response->status(),
                'error_code' => $errorCode,
                'failure_type' => 'saras_api_error',
                'response_type' => $responseType,
                'endpoint' => self::ENDPOINT_LABEL,
            ],
        );
    }

    private function errorMessage(Response $response, array $data, string $responseType): string
    {
        $message = data_get($data, 'message')
            ?? data_get($data, 'msg')
            ?? data_get($data, 'error')
            ?? data_get($data, 'errorMessage')
            ?? data_get($data, 'addMsg')
            ?? data_get($data, 'result.error')
            ?? data_get($data, 'result.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        if ($responseType !== 'json') {
            return self::BACKEND_BLOCKED_MESSAGE;
        }

        $body = trim($response->body());

        if ($body !== '') {
            return Str::limit($body, 180);
        }

        return match ($response->status()) {
            401 => 'Saras face login unauthorized.',
            403 => 'Saras face login forbidden.',
            404 => 'Saras face login endpoint was not found.',
            default => 'Saras face login unavailable.',
        };
    }

    private function responseType(Response $response): string
    {
        $contentType = Str::lower($response->header('Content-Type', ''));
        $body = trim($response->body());
        $bodyStart = Str::lower(Str::limit(ltrim($body), 64, ''));

        if (str_contains($contentType, 'json') || is_array($response->json())) {
            return 'json';
        }

        if ($body === '') {
            return 'empty';
        }

        if (str_contains($contentType, 'html') || str_starts_with($bodyStart, '<!doctype html') || str_starts_with($bodyStart, '<html')) {
            return 'html';
        }

        return 'text';
    }

    private function isWafBlocked(Response $response, string $responseType): bool
    {
        if (! $response->forbidden()) {
            return false;
        }

        $body = Str::lower($response->body());

        return $responseType === 'html'
            || str_contains($body, 'cloudflare')
            || str_contains($body, 'just a moment')
            || str_contains($body, 'challenge-platform');
    }

    /**
     * @return array<string, mixed>|string
     */
    private function loggableBody(Response $response, array $data, string $responseType): array|string
    {
        if ($responseType === 'json') {
            return $data;
        }

        return Str::limit(trim(strip_tags($response->body())), 160);
    }

    private function confidence(array $data): float
    {
        $confidence = data_get($data, 'confidence')
            ?? data_get($data, 'result.confidence')
            ?? data_get($data, 'result.details.match.score')
            ?? data_get($data, 'result.details.match.confidence');

        if (is_numeric($confidence)) {
            return (float) $confidence;
        }

        return match ($confidence) {
            'very_high' => 95,
            'high' => 85,
            'medium' => 60,
            'low' => 30,
            default => 100,
        };
    }

    private function base64Image(UploadedFile $file): string
    {
        return base64_encode((string) file_get_contents($file->getRealPath()));
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
