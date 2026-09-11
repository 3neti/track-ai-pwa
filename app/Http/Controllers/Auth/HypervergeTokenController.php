<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HypervergeTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) config('face_auth.hyperverge_capture.enabled'), 404);

        $allowedWorkflows = $this->allowedWorkflows();
        $defaultWorkflow = (string) config('face_auth.hyperverge_capture.workflow', 'enrol');

        $validated = $request->validate([
            'workflow' => ['nullable', 'string', Rule::in($allowedWorkflows)],
        ]);

        $workflowId = filled($validated['workflow'] ?? null)
            ? (string) $validated['workflow']
            : $defaultWorkflow;
        $transactionId = 'track-ai-face-'.Str::slug($workflowId).'-'.Str::uuid()->toString();

        if (config('hyperverge.mode') !== 'live') {
            return response()->json([
                'ok' => true,
                'mode' => 'stub',
                'access_token' => 'stub-hyperverge-token',
                'workflow_id' => $workflowId,
                'transaction_id' => $transactionId,
                'sdk_url' => config('face_auth.hyperverge_capture.sdk_url'),
            ]);
        }

        if (! filled(config('hyperverge.app_id')) || ! filled(config('hyperverge.app_key'))) {
            return response()->json([
                'ok' => false,
                'message' => 'HyperVerge credentials are not configured.',
            ], 422);
        }

        $response = Http::timeout((int) config('hyperverge.timeout', 30))
            ->connectTimeout(10)
            ->acceptJson()
            ->asJson()
            ->post((string) config('face_auth.hyperverge_capture.auth_url'), [
                'appId' => config('hyperverge.app_id'),
                'appKey' => config('hyperverge.app_key'),
                'expiry' => (int) config('face_auth.hyperverge_capture.token_expiry_seconds', 900),
            ]);

        if (! $response->successful()) {
            $data = $response->json() ?? [];

            return response()->json([
                'ok' => false,
                'message' => data_get($data, 'message')
                    ?? data_get($data, 'msg')
                    ?? 'Unable to start HyperVerge capture.',
                'status' => $response->status(),
            ], 422);
        }

        $data = $response->json() ?? [];
        $accessToken = data_get($data, 'result.token')
            ?? data_get($data, 'result.accessToken')
            ?? data_get($data, 'token')
            ?? data_get($data, 'accessToken')
            ?? data_get($data, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            return response()->json([
                'ok' => false,
                'message' => 'HyperVerge did not return an access token.',
                'response_keys' => array_keys($data),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'live',
            'access_token' => $accessToken,
            'workflow_id' => $workflowId,
            'transaction_id' => $transactionId,
            'sdk_url' => config('face_auth.hyperverge_capture.sdk_url'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedWorkflows(): array
    {
        return collect([
            config('face_auth.hyperverge_capture.workflow', 'enrol'),
            config('hyperverge.workflows.enroll', 'enrol'),
            config('hyperverge.workflows.face_auth', 'faceAuth'),
        ])
            ->filter(fn (mixed $workflow): bool => is_string($workflow) && $workflow !== '')
            ->unique()
            ->values()
            ->all();
    }
}
