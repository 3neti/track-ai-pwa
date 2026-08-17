<?php

namespace App\Services\Branding;

use App\Contracts\SarasClientInterface;
use App\Models\User;
use App\Services\Saras\DTO\ProjectDTO;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BrandingResolver
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * @return array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}
     */
    public function resolve(?User $user = null): array
    {
        $fallback = $this->fallback();

        if (! $user || ! config('branding.remote.enabled', true) || config('saras.mode') !== 'live') {
            return $fallback;
        }

        $projectId = (string) config('saras.project_id', '');
        $cacheKey = "branding:saras:user:{$user->id}:project:{$projectId}";
        $ttl = max(0, (int) config('branding.remote.cache_ttl_seconds', 300));

        return Cache::remember($cacheKey, $ttl, function () use ($fallback, $projectId, $user): array {
            $guard = Auth::guard();
            $previousUser = $guard->user();

            try {
                $guard->setUser($user);

                return $this->resolveFromSaras($projectId, $fallback);
            } catch (\Throwable $e) {
                Log::warning('BrandingResolver: Failed to resolve Saras branding', [
                    'user_id' => $user->id,
                    'project_id' => $projectId,
                    'error' => $e->getMessage(),
                ]);

                return $fallback;
            } finally {
                if ($previousUser instanceof User) {
                    $guard->setUser($previousUser);
                }
            }
        });
    }

    /**
     * @param  array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}  $fallback
     * @return array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}
     */
    protected function resolveFromSaras(string $projectId, array $fallback): array
    {
        $response = $this->sarasClient->getProjectsForUser(page: 1, perPage: 50);
        $project = $this->findProject($response->projects, $projectId);

        if (! $project) {
            return $fallback;
        }

        $metadata = $project->metadata;
        $projectMeta = (array) data_get($metadata, 'projectMeta', []);
        $branding = (array) data_get($metadata, 'branding', []);
        $uiSettings = data_get($metadata, 'uiSettings', []);

        return [
            'name' => $this->firstString([
                data_get($branding, 'name'),
                data_get($branding, 'appName'),
                data_get($branding, 'label'),
                data_get($projectMeta, 'appName'),
                data_get($projectMeta, 'label'),
                $project->name,
            ]) ?? $fallback['name'],
            'short_name' => $this->firstString([
                data_get($branding, 'shortName'),
                data_get($branding, 'short_name'),
                data_get($projectMeta, 'shortName'),
                data_get($projectMeta, 'prefix'),
                $project->name,
            ]) ?? $fallback['short_name'],
            'square_logo' => $this->firstString([
                data_get($branding, 'squareLogo'),
                data_get($branding, 'square_logo'),
                data_get($branding, 'logo.square'),
                data_get($projectMeta, 'squareLogo'),
                data_get($projectMeta, 'logoUrl'),
                data_get($uiSettings, 'branding.squareLogo'),
            ]) ?? $fallback['square_logo'],
            'rectangle_logo' => $this->firstString([
                data_get($branding, 'rectangleLogo'),
                data_get($branding, 'rectangle_logo'),
                data_get($branding, 'logo.rectangle'),
                data_get($projectMeta, 'rectangleLogo'),
                data_get($projectMeta, 'wordmarkUrl'),
                data_get($uiSettings, 'branding.rectangleLogo'),
            ]) ?? $fallback['rectangle_logo'],
            'source' => 'saras',
            'project_id' => $projectId ?: $project->externalId,
        ];
    }

    /**
     * @param  array<int, ProjectDTO>  $projects
     */
    protected function findProject(array $projects, string $projectId): ?ProjectDTO
    {
        foreach ($projects as $project) {
            if ($project->externalId === $projectId || $project->contractId === $projectId) {
                return $project;
            }
        }

        return $projects[0] ?? null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}
     */
    protected function fallback(): array
    {
        return [
            'name' => (string) config('branding.name'),
            'short_name' => (string) config('branding.short_name'),
            'square_logo' => config('branding.square_logo'),
            'rectangle_logo' => config('branding.rectangle_logo'),
            'source' => 'config',
            'project_id' => config('saras.project_id'),
        ];
    }
}
