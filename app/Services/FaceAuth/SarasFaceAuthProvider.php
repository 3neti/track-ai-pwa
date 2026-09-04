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

            return FaceVerificationResult::error('Connection to Saras face login failed.');
        } catch (Throwable $e) {
            Log::error('Saras face login failed', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);

            return FaceVerificationResult::error('Saras face login failed.');
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
        $message = $this->errorMessage($response, $data);

        Log::warning('Saras face login API error', [
            'status' => $response->status(),
            'body' => app()->isProduction() ? '[redacted]' : $data,
            'transactionId' => $transactionId,
        ]);

        return FaceVerificationResult::error(
            $message,
            $data,
            [
                'status' => $response->status(),
            ],
        );
    }

    private function errorMessage(Response $response, array $data): string
    {
        $message = data_get($data, 'message')
            ?? data_get($data, 'error')
            ?? data_get($data, 'errorMessage')
            ?? data_get($data, 'result.error')
            ?? data_get($data, 'result.message');

        if (is_string($message) && $message !== '') {
            return $message;
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
