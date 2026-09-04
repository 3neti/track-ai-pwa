<?php

namespace App\Services\FaceAuth;

use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SarasFaceRegistrationService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $registerPath,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function register(User $user, UploadedFile $selfie, UploadedFile $document, ?string $bearerToken = null): array
    {
        try {
            $token = $bearerToken ?: (string) $user->saras_access_token;

            $response = Http::timeout($this->timeout)
                ->connectTimeout(10)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint($this->registerPath), [
                    'image1' => $this->base64Image($selfie),
                    'image2' => $this->base64Image($document),
                ]);

            if (! $response->successful()) {
                $data = $response->json() ?? [];

                Log::warning('Saras face registration API error', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'body' => app()->isProduction() ? '[redacted]' : $data,
                ]);

                return [
                    'ok' => false,
                    'message' => data_get($data, 'message')
                        ?? data_get($data, 'msg')
                        ?? data_get($data, 'error')
                        ?? data_get($data, 'addMsg')
                        ?? 'Saras face registration failed.',
                    'raw' => $data,
                ];
            }

            $this->markRegistered($user, $response->json() ?? []);

            return [
                'ok' => true,
                'raw' => $response->json() ?? [],
            ];
        } catch (ConnectionException $e) {
            Log::error('Saras face registration connection failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Connection to Saras face registration failed.'];
        } catch (Throwable $e) {
            Log::error('Saras face registration failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Saras face registration failed.'];
        }
    }

    private function markRegistered(User $user, array $data): void
    {
        FaceEnrollment::query()
            ->where('user_id', $user->id)
            ->where('provider', 'saras')
            ->update(['status' => 'inactive']);

        FaceEnrollment::create([
            'user_id' => $user->id,
            'provider' => 'saras',
            'disk' => 'local',
            'path' => "saras-face-authentication/{$user->id}",
            'status' => 'active',
            'metadata' => [
                'registered_with_saras' => true,
                'trace_id' => $data['traceId'] ?? $data['trace_id'] ?? null,
            ],
            'enrolled_at' => now(),
        ]);
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
