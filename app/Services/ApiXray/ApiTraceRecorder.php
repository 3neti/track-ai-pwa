<?php

declare(strict_types=1);

namespace App\Services\ApiXray;

use App\Models\ApiTrace;
use Illuminate\Support\Facades\Auth;

final class ApiTraceRecorder
{
    private const REDACT_KEYS = [
        'authorization', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'secret', 'password', 'client_secret',
        'cookie', 'set-cookie', 'saras_access_token',
    ];

    public function record(
        string $method,
        string $endpoint,
        array $requestData,
        array $responseData,
        int $status,
        float $durationMs,
        ?string $error = null,
    ): ApiTrace {
        $operation = $this->inferOperation($method, $endpoint);
        $traceId = $responseData['traceId'] ?? null;

        return ApiTrace::create([
            'trace_id' => $traceId,
            'provider' => 'saras',
            'operation' => $operation,
            'method' => $method,
            'endpoint' => $this->stripQueryParams($endpoint),
            'request_body' => $this->redact($requestData),
            'response_body' => $this->compactResponse($responseData),
            'status_code' => $status,
            'duration_ms' => round($durationMs, 1),
            'user_id' => Auth::id(),
            'error_message' => $error ? substr($error, 0, 255) : $this->extractErrorMessage($responseData),
        ]);
    }

    private function inferOperation(string $method, string $endpoint): string
    {
        $path = $this->stripQueryParams($endpoint);

        return match (true) {
            str_contains($path, '/createProcess') => 'createProcess',
            str_contains($path, '/getProcess') => 'getProcess',
            str_contains($path, '/updateFiles') => 'updateFiles',
            str_contains($path, '/updateProcessField') => 'updateProcessField',
            str_contains($path, '/createSignedStorage') => 'createSignedStorage',
            str_contains($path, '/closeSignedStorage') => 'closeSignedStorage',
            str_contains($path, '/createStorage') => 'uploadFiles',
            str_contains($path, '/executeWorkflow') => 'executeWorkflow',
            str_contains($path, '/getWorkflowRuns') => 'getWorkflowRuns',
            str_contains($path, '/getProjectsForUser') => 'getProjectsForUser',
            str_contains($path, '/getUserDetails') => 'getUserDetails',
            str_contains($path, '/userLogin') => 'userLogin',
            default => "{$method} {$path}",
        };
    }

    private function stripQueryParams(string $endpoint): string
    {
        return (string) preg_replace('/\?.*$/', '', $endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Strip verbose nested user/tenant objects from Saras responses.
     */
    private function compactResponse(array $data): array
    {
        // Remove verbose nested userId/tenantId objects to save space
        if (isset($data['process'])) {
            unset(
                $data['process']['userId'],
                $data['process']['currentAssignedUserId'],
                $data['process']['tenantId'],
            );
        }

        if (isset($data['runId'])) {
            unset(
                $data['runId']['userId'],
                $data['runId']['tenantId'],
                $data['runId']['workflowId']['userId'],
                $data['runId']['workflowId']['tenantId'],
            );
        }

        // Compact workflow runs list
        if (isset($data['runs']) && is_array($data['runs'])) {
            $data['runs'] = array_map(fn ($r) => [
                'id' => $r['id'] ?? null,
                'state' => $r['state'] ?? null,
                'flowState' => $r['flowState'] ?? null,
            ], $data['runs']);
        }

        // Compact projects list
        if (isset($data['projects']) && is_array($data['projects'])) {
            $data['projects'] = array_map(fn ($p) => [
                'id' => $p['id'] ?? null,
                'name' => $p['projectMeta']['name'] ?? null,
            ], $data['projects']);
        }

        return $data;
    }

    private function extractErrorMessage(array $data): ?string
    {
        $msg = $data['msg'] ?? $data['message'] ?? $data['error'] ?? null;

        if (! is_string($msg)) {
            return null;
        }

        $addMsg = $data['addMsg'] ?? null;

        if (is_string($addMsg)) {
            return substr("{$msg}: {$addMsg}", 0, 255);
        }

        return substr($msg, 0, 255);
    }
}
