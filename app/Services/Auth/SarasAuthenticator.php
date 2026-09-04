<?php

namespace App\Services\Auth;

use App\Models\Project;
use App\Models\User;
use App\Services\FaceAuth\SarasFaceRegistrationStatusService;
use App\Services\Saras\SarasProjectContextResolver;
use App\Services\TrackAI\ContractService;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class SarasAuthenticator
{
    public function __construct(
        protected ContractService $contractService,
        protected ProjectProgressService $projectProgressService,
        protected SarasProjectContextResolver $contextResolver,
        protected SarasFaceRegistrationStatusService $faceRegistrationStatusService,
    ) {}

    /**
     * Authenticate user against Saras API (live mode) or local DB (stub mode).
     */
    public function authenticate(Request $request): ?User
    {
        // Support both 'email' and 'username' fields (Fortify uses username config)
        $identifier = $request->input('email') ?? $request->input('username');
        $password = $request->input('password');
        $projectId = $request->input('saras_project_id');

        if (empty($identifier) || empty($password)) {
            return null;
        }

        $mode = config('saras.mode');

        // In stub mode, use standard local authentication
        if ($mode !== 'live') {
            return $this->authenticateLocally($identifier, $password);
        }

        // In live mode, authenticate against Saras API
        return $this->authenticateWithSaras($identifier, $password, is_string($projectId) ? $projectId : null);
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
    protected function authenticateWithSaras(string $email, string $password, ?string $projectId = null): ?User
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
            $accessToken = $tokenData['access_token']
                ?? $tokenData['accessToken']
                ?? $tokenData['authToken']
                ?? $tokenData['jwt']
                ?? $tokenData['token']
                ?? data_get($tokenData, 'data.access_token')
                ?? data_get($tokenData, 'data.accessToken')
                ?? data_get($tokenData, 'data.authToken')
                ?? data_get($tokenData, 'data.jwt')
                ?? data_get($tokenData, 'data.token')
                ?? null;
            $expiresIn = $tokenData['expires_in'] ?? $tokenData['expiresIn'] ?? 3600;

            if (! $accessToken) {
                Log::warning('Saras auth succeeded but no token returned', [
                    'email' => $email,
                ]);

                return null;
            }

            if ($this->requiresFaceRegistration($tokenData) || $this->requiresFaceRegistrationByStatus($email)) {
                $user = $this->getOrCreateUser($email, $password, [], null);

                request()->session()->put('saras_face_registration_required', true);
                request()->session()->put('saras_face_registration_token', $accessToken);
                request()->session()->put('saras_face_registration_token_expires_at', now()->addSeconds(max($expiresIn - 60, 60))->toISOString());

                Log::info('Saras login requires face registration', [
                    'user_id' => $user->id,
                    'email' => $email,
                ]);

                return $user;
            }

            // Fetch user details from Saras
            $userResponse = Http::timeout($timeout)
                ->withToken($accessToken)
                ->acceptJson()
                ->get("{$baseUrl}/users/getUserDetails");

            $userData = $userResponse->successful() ? $userResponse->json() : [];

            // JIT provision: get or create local user with their token
            $user = $this->getOrCreateUser($email, $password, $userData, $accessToken, $expiresIn);

            $selectedProjectId = is_string($projectId) ? trim($projectId) : '';

            if ($selectedProjectId !== '' && $selectedProjectId !== (string) config('saras.project_id', '')) {
                $this->contextResolver->selectProject($user, $selectedProjectId, persist: true);
            }

            $this->syncSarasResourcesForLogin($user);

            return $user;

        } catch (ConnectionException $e) {
            Log::error('Saras connection failed during authentication', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            Log::info('Falling back to local authentication with stored Saras token validation');

            return $this->authenticateLocallyWithValidSarasToken($email, $password);
        }
    }

    /**
     * Authenticate locally only when the user still has a usable Saras token.
     *
     * This allows short Saras outages without creating a broken session that
     * cannot call Saras-backed contract/progress APIs.
     */
    protected function authenticateLocallyWithValidSarasToken(string $identifier, string $password): ?User
    {
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if (! $user->saras_access_token) {
            Log::warning('Local fallback rejected because user has no Saras token', [
                'user_id' => $user->id,
            ]);

            throw ValidationException::withMessages([
                Fortify::username() => 'Saras login is currently unavailable, and this local account has no stored Saras token. Please try again when Saras auth is reachable.',
            ]);
        }

        if ($user->saras_token_expires_at && now()->greaterThan($user->saras_token_expires_at)) {
            Log::warning('Local fallback rejected because Saras token is expired', [
                'user_id' => $user->id,
                'expired_at' => $user->saras_token_expires_at,
            ]);

            throw ValidationException::withMessages([
                Fortify::username() => 'Saras login is currently unavailable, and the stored Saras token is expired. Please try again when Saras auth is reachable.',
            ]);
        }

        return $user;
    }

    /**
     * Get existing user or create new one with Saras data.
     */
    protected function getOrCreateUser(
        string $email,
        string $password,
        array $sarasData,
        ?string $accessToken = null,
        int $expiresIn = 3600
    ): User {
        $user = User::where('email', $email)->first();

        $sarasUserId = $sarasData['id'] ?? null;
        $name = $sarasData['name'] ?? $this->extractNameFromEmail($email);
        $tenant = $sarasData['tenantId'] ?? [];
        $tenantId = is_array($tenant) ? ($tenant['id'] ?? null) : null;
        $tenantName = is_array($tenant) ? ($tenant['name'] ?? null) : null;

        // Calculate token expiry (with 60s buffer)
        $tokenExpiresAt = $accessToken ? now()->addSeconds($expiresIn - 60) : null;

        if ($user) {
            // Update existing user with latest Saras data, password, and token
            $user->update([
                'password' => Hash::make($password),
                'saras_user_id' => $sarasUserId ?? $user->saras_user_id,
                'tenant_id' => $tenantId ?? $user->tenant_id,
                'tenant_name' => $tenantName ?? $user->tenant_name,
                'saras_access_token' => $accessToken,
                'saras_token_expires_at' => $tokenExpiresAt,
            ]);

            Log::info('Updated existing user from Saras login', [
                'user_id' => $user->id,
                'email' => $email,
                'tenant' => $tenantName,
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
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName,
            'saras_access_token' => $accessToken,
            'saras_token_expires_at' => $tokenExpiresAt,
        ]);

        Log::info('JIT provisioned new user from Saras login', [
            'user_id' => $user->id,
            'email' => $email,
            'saras_user_id' => $sarasUserId,
            'tenant' => $tenantName,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $tokenData
     */
    protected function requiresFaceRegistration(array $tokenData): bool
    {
        $markers = [
            'face_registration_required',
            'faceRegistrationRequired',
            'register_face',
            'registerFace',
            'requires_face_registration',
            'requiresFaceRegistration',
        ];

        foreach ($markers as $marker) {
            if (($tokenData[$marker] ?? false) === true) {
                return true;
            }
        }

        $tokenType = strtolower((string) ($tokenData['token_type'] ?? $tokenData['tokenType'] ?? ''));
        $purpose = strtolower((string) ($tokenData['purpose'] ?? $tokenData['scope'] ?? ''));
        $authStrategy = strtoupper((string) ($tokenData['authStrategy'] ?? $tokenData['auth_strategy'] ?? ''));
        $faceRegistered = filter_var(
            $tokenData['faceRegistered'] ?? $tokenData['face_registered'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        return ($authStrategy === 'FACE' && $faceRegistered === false)
            || str_contains($tokenType, 'signup')
            || str_contains($tokenType, 'self')
            || str_contains($purpose, 'face_registration')
            || str_contains($purpose, 'self_signup');
    }

    protected function requiresFaceRegistrationByStatus(string $email): bool
    {
        $status = $this->faceRegistrationStatusService->check($email);

        return $status['ok'] === true && ($status['face_registration_required'] ?? false) === true;
    }

    /**
     * Refresh local Saras-backed resources after a successful Saras login.
     *
     * Fortify has not completed the web login yet, so temporarily bind the
     * authenticated user while SarasTokenManager reads the newly stored token.
     */
    protected function syncSarasResourcesForLogin(User $user): void
    {
        if (config('saras.mode') !== 'live') {
            return;
        }

        $guard = Auth::guard();
        $previousUser = $guard->user();

        try {
            $guard->setUser($user);
            $context = $this->contextResolver->resolve($user, refresh: true);
            $contracts = $this->contractService->syncContractsFromSaras();
            $project = Project::where('external_id', $context->projectId)->first()
                ?? Project::where('contract_id', $context->projectId)->first()
                ?? Project::query()->first();
            $progressCount = $project
                ? $this->projectProgressService->syncProjectProgressFromSaras($user, $project)->count()
                : 0;

            Log::info('Saras resources synced after login', [
                'user_id' => $user->id,
                'project_id' => $context->projectId,
                'contract_count' => $contracts->count(),
                'project_progress_count' => $progressCount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Saras resource sync after login failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($previousUser instanceof User) {
                $guard->setUser($previousUser);
            }
        }
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
