<?php

namespace App\Services\Saras;

use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SarasTokenManager implements SarasTokenManagerInterface
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected string $cacheKey;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('saras.base_url');
        $this->username = config('saras.username');
        $this->password = config('saras.password');
        $this->cacheKey = config('saras.token_cache_key', 'saras:token');
        $this->timeout = config('saras.timeout', 30);
    }

    /**
     * Get a valid access token, fetching a new one if necessary.
     *
     * @throws SarasApiException
     */
    public function getAccessToken(): string
    {
        $cached = Cache::get($this->cacheKey);

        if ($cached && isset($cached['access_token'], $cached['expires_at'])) {
            // Check if token is still valid (with 60s buffer already applied when cached)
            if (now()->timestamp < $cached['expires_at']) {
                return $cached['access_token'];
            }
        }

        return $this->fetchNewToken();
    }

    /**
     * Invalidate the cached token.
     */
    public function invalidateToken(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Fetch a new token from Saras API.
     *
     * @throws SarasApiException
     */
    protected function fetchNewToken(): string
    {
        $requestId = Str::uuid()->toString();

        Log::info('Saras API: Fetching new access token', [
            'request_id' => $requestId,
            'endpoint' => '/users/userLogin',
        ]);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post('/users/userLogin', [
                    'client_id' => $this->username,
                    'client_secret' => $this->password,
                ]);

            if (! $response->successful()) {
                Log::error('Saras API: Authentication failed', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                ]);

                throw SarasApiException::authFailed(
                    $response->json('message') ?? 'Authentication failed with status '.$response->status()
                );
            }

            $data = $response->json();

            // Extract token and expiry from response
            // Saras returns: { access_token, expires_in (seconds), ... }
            $accessToken = $data['access_token'] ?? $data['token'] ?? null;
            $expiresIn = $data['expires_in'] ?? $data['expiresIn'] ?? 3600; // Default 1 hour

            if (! $accessToken) {
                Log::error('Saras API: No access token in response', [
                    'request_id' => $requestId,
                ]);

                throw SarasApiException::authFailed('No access token in response');
            }

            // Cache with 60 second buffer before actual expiry
            $expiresAt = now()->timestamp + $expiresIn - 60;
            $ttlSeconds = max($expiresIn - 60, 60); // At least 60 seconds TTL

            Cache::put($this->cacheKey, [
                'access_token' => $accessToken,
                'expires_at' => $expiresAt,
            ], $ttlSeconds);

            Log::info('Saras API: Token obtained successfully', [
                'request_id' => $requestId,
                'expires_in_seconds' => $expiresIn,
            ]);

            return $accessToken;

        } catch (ConnectionException $e) {
            Log::error('Saras API: Connection failed during authentication', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            throw SarasApiException::unavailable('/users/userLogin', 'Connection failed', $e);
        }
    }
}
