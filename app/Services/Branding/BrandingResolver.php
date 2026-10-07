<?php

namespace App\Services\Branding;

use App\Models\User;
use App\Services\Saras\SarasProjectContextResolver;

class BrandingResolver
{
    public function __construct(
        protected SarasProjectContextResolver $contextResolver,
    ) {}

    /**
     * @return array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}
     */
    public function resolve(?User $user = null): array
    {
        if (! $user) {
            return $this->fallback();
        }

        if (! config('branding.remote.enabled', true) || config('saras.mode') !== 'live') {
            return $this->authenticatedFallback();
        }

        return $this->contextResolver->resolve($user)->branding;
    }

    /**
     * @return array{interval_ms: int, logos: array<int, array{name: string, square_logo: ?string, rectangle_logo: ?string}>}
     */
    public function authBranding(): array
    {
        $logos = config('branding.login_rotation.logos', []);
        $logos = is_array($logos) ? $logos : [];

        return [
            'interval_ms' => max(1000, (int) config('branding.login_rotation.interval_ms', 10000)),
            'logos' => collect($logos)
                ->map(fn (mixed $logo): array => is_array($logo) ? [
                    'name' => (string) ($logo['name'] ?? 'Brand'),
                    'square_logo' => $logo['square_logo'] ?? null,
                    'rectangle_logo' => $logo['rectangle_logo'] ?? null,
                ] : [])
                ->filter(fn (array $logo): bool => ($logo['square_logo'] ?? null) || ($logo['rectangle_logo'] ?? null))
                ->values()
                ->all(),
        ];
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

    /**
     * @return array{name: string, short_name: string, square_logo: ?string, rectangle_logo: ?string, source: string, project_id: ?string}
     */
    protected function authenticatedFallback(): array
    {
        return [
            'name' => (string) config('branding.authenticated.name', config('branding.name')),
            'short_name' => (string) config('branding.authenticated.short_name', config('branding.short_name')),
            'square_logo' => config('branding.authenticated.square_logo'),
            'rectangle_logo' => config('branding.authenticated.rectangle_logo'),
            'source' => 'config',
            'project_id' => config('saras.project_id'),
        ];
    }
}
