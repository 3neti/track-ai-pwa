<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SyncController extends Controller
{
    /**
     * Display the sync page.
     */
    public function index(): Response
    {
        return Inertia::render('app/Sync');
    }

    /**
     * Process a batch of offline queued jobs.
     */
    public function batch(Request $request): JsonResponse
    {
        $request->validate([
            'jobs' => ['required', 'array'],
            'jobs.*.id' => ['required', 'string'],
            'jobs.*.endpoint' => ['required', 'string'],
            'jobs.*.method' => ['required', 'string', 'in:POST,PUT,PATCH,DELETE'],
            'jobs.*.payload' => ['required', 'array'],
            'jobs.*.idempotency_key' => ['required', 'string'],
        ]);

        $user = $request->user();
        $jobs = $request->input('jobs');
        $results = [];

        foreach ($jobs as $job) {
            $result = $this->processJob($job, $user);
            $results[] = [
                'id' => $job['id'],
                'success' => $result['success'],
                'message' => $result['message'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        }

        AuditLog::log($user->id, 'offline_sync_batch', null, [
            'total_jobs' => count($jobs),
            'successful' => count(array_filter($results, fn ($r) => $r['success'])),
            'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
        ]);

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * Process a single offline job.
     */
    protected function processJob(array $job, $user): array
    {
        try {
            // Route the job to appropriate controller based on endpoint
            $endpoint = $job['endpoint'];
            $payload = $job['payload'];

            // For now, return success for stub mode
            // In production, this would dispatch to the appropriate service
            return [
                'success' => true,
                'message' => 'Job processed successfully',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
