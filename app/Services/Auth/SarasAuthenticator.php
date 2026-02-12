<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SarasAuthenticator
{
    /**
     * Authenticate user against Saras API (live mode) or local DB (stub mode).
     */
    public function authenticate(Request $request): ?User
    {
        // Support both 'email' and 'username' fields (Fortify uses username config)
        $identifier = $request->input('email') ?? $request->input('username');
        $password = $request->input('password');

        if (empty($identifier) || empty($password)) {
            return null;
        }

        $mode = config('saras.mode');

        // In stub mode, use standard local authentication
        if ($mode !== 'live') {
            return $this->authenticateLocally($identifier, $password);
        }

        // In live mode, authenticate against Saras API
        return $this->authenticateWithSaras($identifier, $password);
    }

    /**
     * Authenticate against local database (stub mode).
     */
    protected function authenticateLocally(string $identifier, string $password): ?User
    {
        // Check both email and username fields
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if ($user && Hash::check($password, $user->password)) {
            return $user;
        }

        return null;
    }

    /**
     * Authenticate against Saras API and JIT provision local user.
     */
    protected function authenticateWithSaras(string $email, string $password): ?User
    {
        $baseUrl = config('saras.base_url');
        $timeout = config('saras.timeout', 30);

        try {
            // Attempt to authenticate with Saras
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post("{$baseUrl}/users/userLogin", [
                    'client_id' => $email,
                    'client_secret' => $password,
                ]);

            if (! $response->successful()) {
                Log::info('Saras authentication failed', [
                    'email' => $email,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $tokenData = $response->json();
            $accessToken = $tokenData['access_token'] ?? $tokenData['token'] ?? null;

            if (! $accessToken) {
                Log::warning('Saras auth succeeded but no token returned', [
                    'email' => $email,
                ]);

                return null;
            }

            // Fetch user details from Saras
            $userResponse = Http::timeout($timeout)
                ->withToken($accessToken)
                ->acceptJson()
                ->get("{$baseUrl}/users/getUserDetails");

            $userData = $userResponse->successful() ? $userResponse->json() : [];

            // JIT provision: get or create local user
            return $this->getOrCreateUser($email, $password, $userData);

        } catch (ConnectionException $e) {
            Log::error('Saras connection failed during authentication', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            // Fallback to local auth if Saras is unavailable
            Log::info('Falling back to local authentication');

            return $this->authenticateLocally($email, $password);
        }
    }

    /**
     * Get existing user or create new one with Saras data.
     */
    protected function getOrCreateUser(string $email, string $password, array $sarasData): User
    {
        $user = User::where('email', $email)->first();

        $sarasUserId = $sarasData['id'] ?? null;
        $name = $sarasData['name'] ?? $this->extractNameFromEmail($email);
        $tenant = $sarasData['tenantId'] ?? [];

        if ($user) {
            // Update existing user with latest Saras data and password
            $user->update([
                'password' => Hash::make($password),
                'saras_user_id' => $sarasUserId ?? $user->saras_user_id,
            ]);

            Log::info('Updated existing user from Saras login', [
                'user_id' => $user->id,
                'email' => $email,
            ]);

            return $user;
        }

        // Create new user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'username' => $email,
            'password' => Hash::make($password),
            'saras_user_id' => $sarasUserId,
        ]);

        Log::info('JIT provisioned new user from Saras login', [
            'user_id' => $user->id,
            'email' => $email,
            'saras_user_id' => $sarasUserId,
            'tenant' => $tenant['name'] ?? null,
        ]);

        return $user;
    }

    /**
     * Extract a display name from email.
     */
    protected function extractNameFromEmail(string $email): string
    {
        $name = explode('@', $email)[0];
        $name = str_replace(['.', '_', '-'], ' ', $name);

        return ucwords($name);
    }
}
