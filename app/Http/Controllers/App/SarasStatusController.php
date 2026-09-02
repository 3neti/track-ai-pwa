<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Saras\SarasProjectContextResolver;
use App\Services\TrackAI\ContractService;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SarasStatusController extends Controller
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
        protected SarasTokenManagerInterface $tokenManager,
        protected SarasProjectContextResolver $contextResolver,
        protected ContractService $contractService,
        protected ProjectProgressService $projectProgressService,
    ) {}

    /**
     * Get Saras connection status.
     */
    public function status(): JsonResponse
    {
        $mode = config('saras.mode', 'stub');
        $enabled = config('saras.feature_flags.enabled', true);

        // In stub mode, always healthy
        if ($mode === 'stub') {
            return response()->json([
                'mode' => 'stub',
                'healthy' => true,
                'message' => 'Using stub responses',
            ]);
        }

        // In live mode, check if we can get a token
        if (! $enabled) {
            return response()->json([
                'mode' => 'disabled',
                'healthy' => false,
                'message' => 'Saras integration is disabled',
            ]);
        }

        try {
            // Check if we have a cached token (don't fetch new one for status check)
            $cached = Cache::get(config('saras.token_cache_key', 'saras:token'));
            $hasToken = $cached && isset($cached['access_token']);

            return response()->json([
                'mode' => 'live',
                'healthy' => $hasToken,
                'message' => $hasToken ? 'Connected to Saras' : 'Token not cached, will authenticate on next request',
            ]);
        } catch (SarasApiException $e) {
            return response()->json([
                'mode' => 'live',
                'healthy' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Force a health check by attempting to get a token.
     */
    public function healthCheck(): JsonResponse
    {
        $mode = config('saras.mode', 'stub');

        if ($mode === 'stub') {
            return response()->json([
                'mode' => 'stub',
                'healthy' => true,
                'message' => 'Stub mode - no actual connection',
            ]);
        }

        try {
            $this->tokenManager->getAccessToken();

            return response()->json([
                'mode' => 'live',
                'healthy' => true,
                'message' => 'Successfully authenticated with Saras',
            ]);
        } catch (SarasApiException $e) {
            return response()->json([
                'mode' => 'live',
                'healthy' => false,
                'message' => $e->getMessage(),
                'error_type' => $e->type,
            ], 503);
        }
    }

    public function context(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'context' => $this->contextResolver->resolve($request->user())->toArray(),
            'readiness' => $this->readiness(),
        ]);
    }

    public function refreshContext(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = $this->contextResolver->resolve($user, refresh: true);
        $contracts = collect();
        $progressCount = 0;

        try {
            $contracts = $this->contractService->syncContractsFromSaras();
            $project = Project::where('external_id', $context->projectId)->first()
                ?? Project::where('contract_id', $context->projectId)->first()
                ?? Project::query()->first();

            $progressCount = $project
                ? $this->projectProgressService->syncProjectProgressFromSaras($user, $project)->count()
                : 0;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'context' => $context->toArray(),
                'readiness' => $this->readiness(),
                'message' => 'Saras context refreshed, but resource sync failed: '.$e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'context' => $context->toArray(),
            'readiness' => $this->readiness(),
            'synced' => [
                'contracts' => $contracts->count(),
                'project_progress_reports' => $progressCount,
            ],
            'message' => 'Saras project context, contracts, and progress reports refreshed.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readiness(): array
    {
        return [
            'branding' => [
                'status' => config('branding.remote.enabled', true) ? 'ready' : 'config-only',
                'square_logo_slot' => true,
                'rectangle_logo_slot' => true,
            ],
            'attendance' => [
                'status' => 'needs-test',
                'anti_gps_spoofing' => [
                    'status' => config('saras.location_trust.mode') === 'reject' ? 'enforced' : 'audit',
                    'send_to_saras' => (bool) config('saras.location_trust.send_to_saras', false),
                ],
            ],
            'appliance_tagging' => [
                'status' => 'needs-test',
            ],
            'hyperverge_face_auth' => [
                'status' => config('hyperverge.mode') === 'live' ? 'live' : 'stub',
            ],
        ];
    }
}
