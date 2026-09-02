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
        if (! $user || ! config('branding.remote.enabled', true) || config('saras.mode') !== 'live') {
            return $this->fallback();
        }

        return $this->contextResolver->resolve($user)->branding;
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
