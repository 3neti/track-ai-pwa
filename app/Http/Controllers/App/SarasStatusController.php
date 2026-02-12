<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SarasStatusController extends Controller
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
        protected SarasTokenManagerInterface $tokenManager,
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
}
