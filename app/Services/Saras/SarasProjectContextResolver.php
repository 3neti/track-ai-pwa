<?php

namespace App\Services\Saras;

use App\Contracts\SarasClientInterface;
use App\Models\User;
use App\Services\Saras\DTO\ProjectDTO;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SarasProjectContextResolver
{
    public const SESSION_PROJECT_ID = 'saras.selected_project_id';

    /**
     * @var array<string, array<int, string>>
     */
    private const SUBPROJECT_ALIASES = [
        'attendance' => ['attendance', 'attendancemanagement', 'timeattendance', 'timeandattendance'],
        'trackdata' => ['trackdata', 'track_data', 'track-data', 'track data', 'inventory', 'tagging', 'assettagging', 'appliancetagging'],
        'progress' => ['progress'],
        'project_progress' => ['projectprogress', 'project_progress', 'project-progress', 'project progress', 'constructionprogress', 'progressreport'],
        'contract_ai' => ['contractai', 'contract_ai', 'contract-ai', 'contract ai', 'bidcontracts', 'contracts'],
    ];

    public function __construct(
        protected SarasClientInterface $sarasClient,
    ) {}

    public function resolve(?User $user = null, bool $refresh = false): SarasProjectContext
    {
        $user ??= Auth::user();
        $projectId = $this->selectedProjectId($user);
        $fallback = $this->fallbackContext($projectId);

        if (! $user || config('saras.mode') !== 'live' || ! config('saras.feature_flags.enabled', true)) {
            return $fallback;
        }

        $cacheKey = $this->cacheKey($user, $projectId);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $ttl = max(0, (int) config('saras.project_context.cache_ttl_seconds', 300));

        return Cache::remember($cacheKey, $ttl, function () use ($fallback, $projectId, $user): SarasProjectContext {
            $guard = Auth::guard();
            $previousUser = $guard->user();

            try {
                $guard->setUser($user);

                return $this->resolveFromSaras($projectId, $fallback);
            } catch (\Throwable $e) {
                Log::warning('SarasProjectContext: Failed to resolve remote project context', [
                    'user_id' => $user->id,
                    'project_id' => $projectId,
                    'error' => $e->getMessage(),
                ]);

                return new SarasProjectContext(
                    projectId: $fallback->projectId,
                    projectName: $fallback->projectName,
                    source: 'config',
                    subprojectIds: $fallback->subprojectIds,
                    subprojectSources: $fallback->subprojectSources,
                    branding: $fallback->branding,
                    message: 'Using configured Saras IDs because remote project context could not be loaded.',
                );
            } finally {
                if ($previousUser instanceof User) {
                    $guard->setUser($previousUser);
                } else {
                    $guard->forgetUser();
                }
            }
        });
    }

    public function forget(?User $user = null): void
    {
        $user ??= Auth::user();

        if (! $user) {
            return;
        }

        Cache::forget($this->cacheKey($user, $this->selectedProjectId($user)));
        Cache::forget($this->cacheKey($user, (string) config('saras.project_id', '')));
    }

    public function selectedProjectId(?User $user = null): string
    {
        $sessionProjectId = $this->sessionProjectId();

        if ($sessionProjectId) {
            return $sessionProjectId;
        }

        $savedProjectId = $user?->selected_saras_project_id;

        if (is_string($savedProjectId) && trim($savedProjectId) !== '') {
            return trim($savedProjectId);
        }

        return (string) config('saras.project_id', '');
    }

    /**
     * @return array<int, array{id: string, contract_id: string, name: string, tenant_id: ?string, tenant_name: ?string, is_default: bool, is_selected: bool}>
     */
    public function availableProjectOptions(?User $user = null): array
    {
        $user ??= Auth::user();
        $selectedProjectId = $this->selectedProjectId($user);

        if (! $user || config('saras.mode') !== 'live' || ! config('saras.feature_flags.enabled', true)) {
            return [$this->configuredProjectOption($selectedProjectId)];
        }

        try {
            return $this->withAuthenticatedUser($user, function () use ($selectedProjectId): array {
                $response = $this->sarasClient->getProjectsForUser(page: 1, perPage: 50);

                return collect($response->projects)
                    ->filter(fn (ProjectDTO $project): bool => $project->externalId !== '')
                    ->map(fn (ProjectDTO $project): array => $this->projectOption($project, $selectedProjectId))
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            Log::warning('SarasProjectContext: Failed to list selectable projects', [
                'user_id' => $user->id,
                'selected_project_id' => $selectedProjectId,
                'error' => $e->getMessage(),
            ]);

            return [$this->configuredProjectOption($selectedProjectId)];
        }
    }

    public function selectProject(User $user, ?string $projectId, bool $persist = true): SarasProjectContext
    {
        $projectId = trim((string) ($projectId ?: config('saras.project_id', '')));
        $previousProjectId = $this->selectedProjectId($user);

        if ($projectId === '') {
            throw ValidationException::withMessages([
                'saras_project_id' => 'Enter a Saras project ID or keep the configured default.',
            ]);
        }

        if (config('saras.mode') === 'live' && config('saras.feature_flags.enabled', true)) {
            $this->assertProjectIsAvailableToUser($user, $projectId);
        }

        $this->putSessionProjectId($projectId);

        if ($persist) {
            $user->forceFill(['selected_saras_project_id' => $projectId])->save();
        }

        Cache::forget($this->cacheKey($user, $previousProjectId));
        Cache::forget($this->cacheKey($user, $projectId));

        return $this->resolve($user, refresh: true);
    }

    public function subProjectId(string $key, ?string $fallbackKey = null, ?User $user = null): string
    {
        $value = $this->resolve($user)->subProjectId($key, $fallbackKey);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $configured = config("saras.subproject_ids.{$key}");
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if ($fallbackKey) {
            $fallback = config("saras.subproject_ids.{$fallbackKey}");
            if (is_string($fallback) && trim($fallback) !== '') {
                return trim($fallback);
            }
        }

        throw new \RuntimeException("Saras subproject ID [{$key}] is not configured.");
    }

    protected function resolveFromSaras(string $projectId, SarasProjectContext $fallback): SarasProjectContext
    {
        $response = $this->sarasClient->getProjectsForUser(page: 1, perPage: 50);
        $projects = $response->projects;
        $project = $this->findProject($projects, $projectId);

        if (! $project) {
            return $fallback;
        }

        $remoteSubprojects = $this->extractSubprojectsFromProjects($projects);
        $subprojectIds = [];
        $sources = [];

        foreach (array_keys(config('saras.subproject_ids', [])) as $key) {
            $remoteId = $remoteSubprojects[$key] ?? null;
            $configuredId = config("saras.subproject_ids.{$key}");

            $subprojectIds[$key] = $remoteId ?: (is_string($configuredId) ? $configuredId : null);
            $sources[$key] = $remoteId ? 'saras' : 'config';
        }

        return new SarasProjectContext(
            projectId: $projectId ?: $project->externalId,
            projectName: $project->name,
            source: 'saras',
            subprojectIds: $subprojectIds,
            subprojectSources: $sources,
            branding: $this->brandingFromProject($project, $fallback->branding, $projectId),
            rawProject: $project->metadata,
        );
    }

    /**
     * @param  array<int, ProjectDTO>  $projects
     */
    public function findProject(array $projects, string $projectId): ?ProjectDTO
    {
        if ($projectId === '') {
            return $projects[0] ?? null;
        }

        foreach ($projects as $project) {
            if ($this->projectMatches($project, $projectId)) {
                return $project;
            }
        }

        return $projects[0] ?? null;
    }

    public function projectMatches(ProjectDTO $project, string $projectId): bool
    {
        $candidateIds = array_filter([
            $project->externalId,
            $project->contractId,
            data_get($project->metadata, 'id'),
            data_get($project->metadata, 'projectMeta.id'),
            data_get($project->metadata, 'projectMeta.projectId'),
            data_get($project->metadata, 'metaDetails.parentId'),
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return in_array($projectId, $candidateIds, true);
    }

    /**
     * @return array<string, string>
     */
    protected function extractSubprojectsFromProjects(array $projects): array
    {
        $found = [];

        foreach ($projects as $project) {
            if (! $project instanceof ProjectDTO) {
                continue;
            }

            foreach ($this->extractSubprojects($project->metadata) as $key => $id) {
                $found[$key] ??= $id;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    protected function extractSubprojects(array $metadata): array
    {
        $found = [];

        foreach ($this->flattenProjectNodes($metadata) as $node) {
            $id = $this->firstString([
                data_get($node, 'id'),
                data_get($node, 'subProjectId.id'),
                data_get($node, 'subProjectId'),
                data_get($node, 'value'),
            ]);

            if (! $id) {
                continue;
            }

            $labels = [
                data_get($node, 'projectMeta.projectId'),
                data_get($node, 'projectMeta.name'),
                data_get($node, 'name'),
                data_get($node, 'title'),
                data_get($node, 'label'),
                data_get($node, 'displayName'),
                data_get($node, 'pluginDisplayName'),
                data_get($node, 'type'),
                data_get($node, 'key'),
            ];

            foreach (self::SUBPROJECT_ALIASES as $key => $aliases) {
                if (isset($found[$key])) {
                    continue;
                }

                if ($this->labelsMatch($labels, $aliases)) {
                    $found[$key] = $id;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function flattenProjectNodes(array $value): array
    {
        $nodes = [];

        if ($this->looksLikeProjectNode($value)) {
            $nodes[] = $value;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $nodes = array_merge($nodes, $this->flattenProjectNodes($child));
            }
        }

        return $nodes;
    }

    protected function looksLikeProjectNode(array $value): bool
    {
        return $this->firstString([
            data_get($value, 'id'),
            data_get($value, 'subProjectId.id'),
            data_get($value, 'subProjectId'),
        ]) !== null
            && $this->firstString([
                data_get($value, 'projectMeta.projectId'),
                data_get($value, 'projectMeta.name'),
                data_get($value, 'name'),
                data_get($value, 'title'),
                data_get($value, 'label'),
            ]) !== null;
    }

    /**
     * @param  array<int, mixed>  $labels
     * @param  array<int, string>  $aliases
     */
    protected function labelsMatch(array $labels, array $aliases): bool
    {
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '') {
                continue;
            }

            $normalized = $this->normalizeLabel($label);

            foreach ($aliases as $alias) {
                if ($normalized === $this->normalizeLabel($alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}  $fallback
     * @return array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}
     */
    protected function brandingFromProject(ProjectDTO $project, array $fallback, string $projectId): array
    {
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

    protected function fallbackContext(?string $projectId = null): SarasProjectContext
    {
        $subprojectIds = config('saras.subproject_ids', []);
        $subprojectIds = is_array($subprojectIds) ? $subprojectIds : [];
        $projectId ??= (string) config('saras.project_id', '');

        return new SarasProjectContext(
            projectId: $projectId,
            projectName: null,
            source: 'config',
            subprojectIds: $subprojectIds,
            subprojectSources: array_fill_keys(array_keys($subprojectIds), 'config'),
            branding: [
                'name' => (string) config('branding.name'),
                'short_name' => (string) config('branding.short_name'),
                'square_logo' => config('branding.square_logo'),
                'rectangle_logo' => config('branding.rectangle_logo'),
                'source' => 'config',
                'project_id' => $projectId,
            ],
        );
    }

    protected function assertProjectIsAvailableToUser(User $user, string $projectId): void
    {
        $project = $this->withAuthenticatedUser($user, function () use ($projectId): ?ProjectDTO {
            $response = $this->sarasClient->getProjectsForUser(page: 1, perPage: 50);

            return $this->findProject($response->projects, $projectId);
        });

        if (! $project || ! $this->projectMatches($project, $projectId)) {
            throw ValidationException::withMessages([
                'saras_project_id' => 'That Saras project is not available for this account.',
            ]);
        }
    }

    /**
     * @return array{id: string, contract_id: string, name: string, tenant_id: ?string, tenant_name: ?string, is_default: bool, is_selected: bool}
     */
    protected function projectOption(ProjectDTO $project, string $selectedProjectId): array
    {
        $defaultProjectId = (string) config('saras.project_id', '');

        return [
            'id' => $project->externalId,
            'contract_id' => $project->contractId,
            'name' => $project->name,
            'tenant_id' => $project->tenantId,
            'tenant_name' => $project->tenantName,
            'is_default' => $this->projectMatches($project, $defaultProjectId),
            'is_selected' => $this->projectMatches($project, $selectedProjectId),
        ];
    }

    /**
     * @return array{id: string, contract_id: string, name: string, tenant_id: ?string, tenant_name: ?string, is_default: bool, is_selected: bool}
     */
    protected function configuredProjectOption(string $selectedProjectId): array
    {
        $defaultProjectId = (string) config('saras.project_id', '');

        return [
            'id' => $defaultProjectId,
            'contract_id' => $defaultProjectId,
            'name' => (string) config('branding.name', 'Track AI'),
            'tenant_id' => null,
            'tenant_name' => null,
            'is_default' => true,
            'is_selected' => $selectedProjectId === $defaultProjectId,
        ];
    }

    protected function sessionProjectId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $projectId = $request->session()->get(self::SESSION_PROJECT_ID);

        return is_string($projectId) && trim($projectId) !== '' ? trim($projectId) : null;
    }

    protected function putSessionProjectId(string $projectId): void
    {
        if (app()->bound('request') && request()->hasSession()) {
            request()->session()->put(self::SESSION_PROJECT_ID, $projectId);
        }
    }

    protected function withAuthenticatedUser(User $user, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previousUser = $guard->user();

        try {
            $guard->setUser($user);

            return $callback();
        } finally {
            if ($previousUser instanceof User) {
                $guard->setUser($previousUser);
            } else {
                $guard->forgetUser();
            }
        }
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

    protected function normalizeLabel(string $label): string
    {
        return Str::of($label)
            ->lower()
            ->replace(['_', '-', '/', '&'], ' ')
            ->replaceMatches('/[^a-z0-9 ]+/', '')
            ->replaceMatches('/\s+/', '')
            ->toString();
    }

    protected function cacheKey(User $user, string $projectId): string
    {
        return 'saras:project-context:user:'.$user->id.':tenant:'.($user->tenant_id ?: 'none').':project:'.$projectId;
    }
}
