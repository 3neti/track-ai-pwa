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
use Illuminate\Support\Facades\Auth;
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
                    'status' => config('saras.location_trust.mode') === 'enforce' ? 'enforced' : 'audit',
                    'mode' => config('saras.location_trust.mode', 'audit'),
                    'send_to_saras' => (bool) config('saras.location_trust.send_to_saras', false),
                    'signals' => [
                        'browser_coordinates',
                        'gps_accuracy',
                        'position_age',
                        'impossible_travel',
                    ],
                    'thresholds' => [
                        'max_accuracy_meters' => (int) config('saras.location_trust.max_accuracy_meters', 100),
                        'max_position_age_seconds' => (int) config('saras.location_trust.max_position_age_seconds', 120),
                        'max_speed_kmh' => (int) config('saras.location_trust.max_speed_kmh', 180),
                    ],
                    'stored_on' => [
                        'attendance_sessions.check_in_location_status',
                        'attendance_sessions.check_out_location_status',
                        'project_progress_reports.location_status',
                    ],
                    'saras_payload_fields' => [
                        'geoAccuracyCheckIn',
                        'locationTrustCheckIn',
                        'locationTrustReasonsCheckIn',
                        'geoAccuracyCheckOut',
                        'locationTrustCheckOut',
                        'locationTrustReasonsCheckOut',
                        'geoAccuracy',
                        'locationTrust',
                        'locationTrustReasons',
                    ],
                ],
            ],
            'appliance_tagging' => [
                'status' => 'needs-test',
            ],
            'hyperverge_face_auth' => [
                'status' => config('face_auth.provider'),
                'base_url' => config('hyperverge.base_url'),
                'liveness_path' => config('hyperverge.liveness_path'),
                'match_path' => config('hyperverge.match_path'),
                'confidence_threshold' => config('hyperverge.confidence_threshold'),
                'workflows' => config('hyperverge.workflows'),
                'credentials_configured' => filled(config('hyperverge.app_id')) && filled(config('hyperverge.app_key')),
                'current_user_enrolled' => Auth::user()?->activeFaceEnrollment()->exists() ?? false,
                'saras' => [
                    'base_url' => config('saras.base_url'),
                    'status_path' => config('face_auth.saras.status_path'),
                    'register_path' => config('face_auth.saras.register_path'),
                    'login_path' => config('face_auth.saras.login_path'),
                ],
            ],
        ];
    }
}
