<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'saras' => $this->getSarasStatus(),
            'debug' => config('app.debug', false),
        ];
    }

    /**
     * Get Saras connection status for sharing with frontend.
     */
    protected function getSarasStatus(): array
    {
        $mode = config('saras.mode', 'stub');
        $enabled = config('saras.feature_flags.enabled', true);

        if ($mode === 'stub') {
            return [
                'mode' => 'stub',
                'healthy' => true,
            ];
        }

        if (! $enabled) {
            return [
                'mode' => 'disabled',
                'healthy' => false,
            ];
        }

        // Check cached token status (don't make API call on every request)
        $cached = Cache::get(config('saras.token_cache_key', 'saras:token'));
        $hasToken = $cached && isset($cached['access_token']);

        return [
            'mode' => 'live',
            'healthy' => $hasToken,
        ];
    }
}
