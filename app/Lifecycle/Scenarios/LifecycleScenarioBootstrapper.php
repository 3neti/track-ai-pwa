<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LifecycleScenarioBootstrapper
{
    /**
     * @param  array<string, mixed>  $scenario
     */
    public function bootstrap(
        array $scenario,
        ?int $userIdOption = null,
        ?int $projectIdOption = null,
        ?string $contractIdOption = null,
        ?int $timeoutOption = null,
        ?int $pollOption = null,
    ): LifecycleScenarioBootstrapResult {
        $userId = $userIdOption
            ?? data_get($scenario, 'user_id')
            ?? config('lifecycle-scenarios.defaults.user_id', 1);

        $projectId = $projectIdOption
            ?? data_get($scenario, 'project_id')
            ?? config('lifecycle-scenarios.defaults.project_id');

        $timeout = $timeoutOption
            ?? data_get($scenario, 'timeout')
            ?? config('lifecycle-scenarios.defaults.timeout', 300);

        $poll = max(1, $pollOption
            ?? data_get($scenario, 'poll')
            ?? config('lifecycle-scenarios.defaults.poll', 10));

        $maxPolls = (int) ceil($timeout / max(1, $poll));

        $user = User::find($userId);

        if (! $user) {
            throw new RuntimeException("Unable to resolve lifecycle user [{$userId}].");
        }

        // Authenticate user in the Auth guard so SarasTokenManager can use Auth::user()
        Auth::login($user);

        // Refresh Saras token if expired or missing (live mode only)
        if (config('saras.mode') === 'live') {
            $this->ensureFreshSarasToken($user);
        }

        $project = $this->resolveProject($projectId);
        $contractId = $this->resolveContractId(
            project: $project,
            contractIdOverride: $contractIdOption ?? data_get($scenario, 'contract_id'),
        );

        return new LifecycleScenarioBootstrapResult(
            user: $user,
            project: $project,
            contractId: $contractId,
            timeout: (int) $timeout,
            poll: (int) $poll,
            maxPolls: $maxPolls,
        );
    }

    /**
     * Ensure the user has a valid (non-expired) Saras access token.
     * If expired or missing, re-authenticate against the Saras login endpoint.
     */
    private function ensureFreshSarasToken(User $user): void
    {
        $token = $user->saras_access_token;
        $expiresAt = $user->saras_token_expires_at;

        if ($token && $expiresAt && now()->lessThan($expiresAt)) {
            return; // Token is still valid
        }

        Log::info('Lifecycle: Refreshing expired Saras token', ['user_id' => $user->id]);

        $baseUrl = config('saras.base_url');

        try {
            $response = Http::timeout((int) config('saras.timeout', 30))
                ->acceptJson()
                ->asJson()
                ->post("{$baseUrl}/users/userLogin", [
                    'client_id' => $user->email,
                    'client_secret' => config('saras.password'),
                ]);
        } catch (ConnectionException $e) {
            Log::error('Lifecycle: Saras token refresh connection failed', [
                'user_id' => $user->id,
                'endpoint' => '/users/userLogin',
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Unable to refresh Saras token for user [{$user->id}]: Saras auth endpoint timed out.",
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to refresh Saras token for user [{$user->id}]: HTTP {$response->status()}"
            );
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? $data['token'] ?? null;
        $expiresIn = $data['expires_in'] ?? $data['expiresIn'] ?? 3600;

        if (! $accessToken) {
            throw new RuntimeException('Saras login succeeded but no token returned.');
        }

        $user->update([
            'saras_access_token' => $accessToken,
            'saras_token_expires_at' => now()->addSeconds($expiresIn - 60),
        ]);

        Log::info('Lifecycle: Saras token refreshed', [
            'user_id' => $user->id,
            'expires_at' => $user->saras_token_expires_at,
        ]);
    }

    private function resolveProject(mixed $projectId): Project
    {
        if ($projectId !== null) {
            $project = Project::find($projectId);

            if (! $project) {
                throw new RuntimeException("Unable to resolve lifecycle project [{$projectId}].");
            }

            return $project;
        }

        $project = Project::where('status', 'active')->first();

        if (! $project) {
            throw new RuntimeException('No active project found for lifecycle scenario. Provide --project= or seed a project.');
        }

        return $project;
    }

    private function resolveContractId(Project $project, mixed $contractIdOverride): string
    {
        if (is_string($contractIdOverride) && trim($contractIdOverride) !== '') {
            $contractId = trim($contractIdOverride);

            $exists = Contract::where('saras_process_id', $contractId)->exists();

            if (! $exists) {
                throw new RuntimeException(
                    "Unable to resolve lifecycle contract [{$contractId}]. Sync contracts first or choose a cached Saras contract process ID."
                );
            }

            return $contractId;
        }

        return $project->contract_id ?: config('saras.default_contract_id', '');
    }
}
