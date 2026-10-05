<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Models\ApiTrace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SarasApiXrayController extends Controller
{
    /**
     * Display the X-Ray page.
     */
    public function index(): Response
    {
        return Inertia::render('developer/SarasApiXray');
    }

    /**
     * API: list traces with filters.
     */
    public function traces(Request $request): JsonResponse
    {
        $query = ApiTrace::query()
            ->where('provider', 'saras')
            ->with('user:id,name')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', "%{$search}%")
                    ->orWhere('operation', 'like', "%{$search}%")
                    ->orWhere('trace_id', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'success') {
                $query->where('status_code', '>=', 200)->where('status_code', '<', 300);
            } elseif ($status === 'error') {
                $query->where('status_code', '>=', 400);
            }
        }

        if ($request->filled('operation')) {
            $query->where('operation', $request->input('operation'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }

        $traces = $query->paginate(25);
        $isPublic = $request->user() === null;

        return response()->json([
            'success' => true,
            'data' => collect($traces->items())
                ->map(fn (ApiTrace $trace): array => $this->tracePayload($trace, $isPublic))
                ->all(),
            'meta' => [
                'current_page' => $traces->currentPage(),
                'last_page' => $traces->lastPage(),
                'total' => $traces->total(),
            ],
        ]);
    }

    /**
     * API: intended Saras payload map for joint confirmation.
     */
    public function payloadMap(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => config('saras.payload_map', []),
        ]);
    }

    /**
     * API: get single trace detail.
     */
    public function show(ApiTrace $apiTrace): JsonResponse
    {
        abort_unless($apiTrace->provider === 'saras', 404);

        return response()->json([
            'success' => true,
            'trace' => $this->tracePayload(
                $apiTrace->load('user:id,name'),
                request()->user() === null,
                includeBodies: true,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tracePayload(ApiTrace $trace, bool $isPublic, bool $includeBodies = false): array
    {
        $payload = [
            'id' => $trace->id,
            'trace_id' => $trace->trace_id,
            'provider' => $trace->provider,
            'operation' => $trace->operation,
            'method' => $trace->method,
            'host' => $this->sarasHost(),
            'url' => $this->sarasUrl($trace->endpoint),
            'endpoint' => $trace->endpoint,
            'status_code' => $trace->status_code,
            'duration_ms' => $trace->duration_ms,
            'error_message' => $trace->error_message,
            'created_at' => $trace->created_at,
            'user' => $isPublic ? null : $trace->user,
        ];

        if (! $isPublic) {
            return [
                ...$payload,
                'request_body' => $trace->request_body,
                'response_body' => $trace->response_body,
            ];
        }

        $redactedBodies = [
            'request_body' => [
                'redacted' => true,
                'message' => 'Request payload is hidden on the public X-Ray view.',
            ],
            'response_body' => $this->publicResponseBody($trace),
        ];

        if ($includeBodies) {
            return [...$payload, ...$redactedBodies];
        }

        return [...$payload, ...$redactedBodies];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicResponseBody(ApiTrace $trace): array
    {
        $response = is_array($trace->response_body) ? $trace->response_body : [];

        return collect([
            'traceId' => $trace->trace_id ?? ($response['traceId'] ?? null),
            'message' => $trace->error_message
                ?? ($response['msg'] ?? null)
                ?? ($response['message'] ?? null)
                ?? ($response['error'] ?? null),
            'status_code' => $trace->status_code,
        ])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();
    }

    private function sarasHost(): string
    {
        $host = parse_url((string) config('saras.base_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '—';
    }

    private function sarasUrl(?string $endpoint): string
    {
        $baseUrl = rtrim((string) config('saras.base_url'), '/');
        $path = '/'.ltrim((string) $endpoint, '/');

        return $baseUrl.$path;
    }
}
