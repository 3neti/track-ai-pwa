<?php

namespace App\Services\FaceAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SarasFaceRegistrationStatusService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $statusPath,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(string $email): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(10)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint($this->statusPath), [
                    'email' => $email,
                ]);

            if (! $response->successful()) {
                Log::warning('Saras face registration status API error', [
                    'email' => $email,
                    'status' => $response->status(),
                    'body' => app()->isProduction() ? '[redacted]' : ($response->json() ?? []),
                ]);

                return [
                    'ok' => false,
                    'face_registration_enabled' => null,
                    'raw' => $response->json() ?? [],
                ];
            }

            $data = $response->json() ?? [];

            return [
                'ok' => true,
                'face_registration_enabled' => $this->enabled($data),
                'raw' => $data,
            ];
        } catch (ConnectionException $e) {
            Log::error('Saras face registration status connection failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error('Saras face registration status failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok' => false,
            'face_registration_enabled' => null,
            'raw' => [],
        ];
    }

    private function enabled(array $data): ?bool
    {
        foreach ([
            'face_registration_enabled',
            'faceRegistrationEnabled',
            'samlLoginEnabled',
            'saml_login_enabled',
            'enabled',
            'isEnabled',
            'success',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }

        return null;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
