<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SarasProbeProjectProgress extends Command
{
    protected $signature = 'saras:probe-progress
        {--skip-workflow : Skip workflow execution probes}
        {--sub-project-id= : Provide ProjectProgress subProjectId if known}';

    protected $description = 'Probe Saras API to discover ProjectProgress resource structure';

    protected string $baseUrl;

    protected string $token;

    protected array $results = [];

    public function handle(): int
    {
        $this->baseUrl = config('saras.base_url');
        $this->info("Saras Base URL: {$this->baseUrl}");
        $this->info('Mode: '.config('saras.mode'));
        $this->newLine();

        // Probe 1: Acquire token
        if (! $this->probeToken()) {
            return self::FAILURE;
        }

        // Probe 2: User details
        $this->probeUserDetails();

        // Probe 3: List projects
        $projects = $this->probeProjects();

        // Probe 4: Discover subProjectId
        $subProjectId = $this->option('sub-project-id');
        if (! $subProjectId) {
            $subProjectId = $this->probeDiscoverSubProjectId();
        }

        if (! $subProjectId) {
            $this->error('Could not discover ProjectProgress subProjectId.');
            $this->info('Known subProjectIds:');
            $this->info('  Attendance: '.config('saras.subproject_ids.attendance'));
            $this->info('  TrackData:  '.config('saras.subproject_ids.trackdata'));
            $this->warn('Please provide it via --sub-project-id=<uuid> or check the Saras dashboard.');
            $this->saveResults();

            return self::FAILURE;
        }

        $this->info("Using ProjectProgress subProjectId: {$subProjectId}");

        // Get a contractId from projects
        $contractId = null;
        if (! empty($projects)) {
            $contractId = $projects[0]['contractId'] ?? $projects[0]['contract_id'] ?? $projects[0]['id'] ?? null;
        }
        $contractId = $contractId ?: config('saras.default_contract_id');

        // Probe 5: Create a ProjectProgress process
        $processId = $this->probeCreateProcess($subProjectId, $contractId);

        // Probe 6: Fetch records
        $this->probeFetchProcesses($subProjectId);

        if (! $this->option('skip-workflow') && $processId) {
            // Probe 7: Execute workflow
            $this->probeExecuteWorkflow($processId);

            // Probe 8: Poll workflow runs
            $this->probeGetWorkflowRuns($processId);
        }

        $this->saveResults();

        return self::SUCCESS;
    }

    protected function probeToken(): bool
    {
        $this->info('=== Probe 1: Acquire Token ===');

        $email = config('saras.username', env('SARAS_USERNAME'));
        $password = config('saras.password', env('SARAS_PASSWORD'));

        if (! $email || ! $password) {
            // Fallback: try reading from .env directly
            $email = env('SARAS_USERNAME');
            $password = env('SARAS_PASSWORD');
        }

        if (! $email || ! $password) {
            $this->error('No Saras credentials found in config or .env');

            return false;
        }

        $this->info("Authenticating as: {$email}");

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(30)
                ->acceptJson()
                ->asJson()
                ->post('/users/userLogin', [
                    'client_id' => $email,
                    'client_secret' => $password,
                ]);

            $this->logProbe('token', '/users/userLogin', 'POST', $response);

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['access_token'] ?? $data['token'] ?? $data['data']['access_token'] ?? '';

                if ($this->token) {
                    $this->info('✓ Token acquired (length: '.strlen($this->token).')');

                    return true;
                }

                // Dump full response to find token location
                $this->warn('Token not found in expected fields. Full response:');
                $this->line(json_encode($data, JSON_PRETTY_PRINT));
            } else {
                $this->error("Login failed: HTTP {$response->status()}");
                $this->line($response->body());
            }
        } catch (\Exception $e) {
            $this->error("Login error: {$e->getMessage()}");
        }

        return false;
    }

    protected function probeUserDetails(): void
    {
        $this->newLine();
        $this->info('=== Probe 2: Get User Details ===');

        try {
            $response = $this->authedRequest()
                ->get('/users/getUserDetails');

            $this->logProbe('user_details', '/users/getUserDetails', 'GET', $response);

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✓ User details retrieved');
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn("getUserDetails failed: HTTP {$response->status()}");
                $this->line($response->body());
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    protected function probeProjects(): array
    {
        $this->newLine();
        $this->info('=== Probe 3: List Projects ===');

        try {
            $response = $this->authedRequest()
                ->get('/process/projects/getProjectsForUser', [
                    'page' => 1,
                    'perPageCount' => 5,
                ]);

            $this->logProbe('projects', '/process/projects/getProjectsForUser', 'GET', $response);

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✓ Projects retrieved');
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $data['data'] ?? $data['projects'] ?? [];
            } else {
                $this->warn("getProjectsForUser failed: HTTP {$response->status()}");
                $this->line($response->body());
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }

        return [];
    }

    protected function probeDiscoverSubProjectId(): ?string
    {
        $this->newLine();
        $this->info('=== Probe 4: Discover ProjectProgress subProjectId ===');

        // Try various discovery endpoints
        $endpoints = [
            '/process/subprojects' => 'GET',
            '/process/getSubProjects' => 'GET',
            '/process/projects/getSubProjects' => 'GET',
        ];

        foreach ($endpoints as $endpoint => $method) {
            $this->info("  Trying: {$method} {$endpoint}");

            try {
                $response = $this->authedRequest()
                    ->get($endpoint);

                $this->logProbe("discover_{$endpoint}", $endpoint, $method, $response);

                if ($response->successful()) {
                    $data = $response->json();
                    $this->info("  ✓ {$endpoint} returned data:");
                    $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    // Try to find ProjectProgress in the response
                    $found = $this->findInArray($data, 'ProjectProgress');
                    if ($found) {
                        $this->info('  Found ProjectProgress reference: '.json_encode($found));

                        return $found['id'] ?? $found['subProjectId'] ?? null;
                    }
                } else {
                    $this->warn("  {$endpoint}: HTTP {$response->status()}");
                }
            } catch (\Exception $e) {
                $this->warn("  {$endpoint}: {$e->getMessage()}");
            }
        }

        $this->warn('Could not auto-discover subProjectId.');

        return null;
    }

    protected function probeCreateProcess(string $subProjectId, ?string $contractId): ?string
    {
        $this->newLine();
        $this->info('=== Probe 5: Create ProjectProgress Process ===');

        // First try minimal payload to see what validation errors reveal
        $minimalFields = [
            'contractId' => $contractId ?? 'test-contract',
            'remarks' => 'API probe test - Track AI',
        ];

        $this->info('Attempting with minimal fields: '.json_encode($minimalFields));

        try {
            $response = $this->authedRequest()
                ->post('/process/createProcess', [
                    'subProjectId' => $subProjectId,
                    'fields' => $minimalFields,
                ]);

            $this->logProbe('create_process_minimal', '/process/createProcess', 'POST', $response);

            $data = $response->json();
            $this->info("Response HTTP {$response->status()}:");
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($response->successful()) {
                $processId = $data['process']['id'] ?? $data['processId'] ?? $data['id'] ?? $data['entryId'] ?? null;
                if ($processId) {
                    $this->info("✓ Process created! processId: {$processId}");

                    return $processId;
                }
            } elseif ($response->status() === 422) {
                $this->warn('Validation error - this reveals required fields:');
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }

        return null;
    }

    protected function probeFetchProcesses(string $subProjectId): void
    {
        $this->newLine();
        $this->info('=== Probe 6: Fetch ProjectProgress Records ===');

        $endpoints = [
            "/process/getProcesses?subProjectId={$subProjectId}" => 'GET',
            "/process/getProcesses?subProjectId={$subProjectId}&page=1&perPageCount=5" => 'GET',
        ];

        foreach ($endpoints as $endpoint => $method) {
            $this->info("  Trying: {$method} {$endpoint}");

            try {
                $response = $this->authedRequest()->get($endpoint);

                $this->logProbe('fetch_processes', $endpoint, $method, $response);

                $this->info("  Response HTTP {$response->status()}:");
                $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if ($response->successful()) {
                    break; // First success wins
                }
            } catch (\Exception $e) {
                $this->warn("  Error: {$e->getMessage()}");
            }
        }
    }

    protected function probeExecuteWorkflow(string $processId): void
    {
        $this->newLine();
        $this->info('=== Probe 7: Execute Completion Workflow ===');

        // Correct workflow ID from ProjectProgress schema (not the old spec doc one)
        $workflowId = 'd702fb25-51ae-4d7f-88fc-132d555b2f00';

        // Correct stageKey from ProjectProgress schema
        $stageKey = 'stage_1779863565116_eqt6';

        $payload = [
            'workflowId' => $workflowId,
            'otherDetails' => [
                'initiator' => 'INITIATOR_PROCESS',
                'processId' => $processId,
                'initiatorMeta' => [
                    'stageKey' => $stageKey,
                ],
            ],
            'payload' => (object) [
                'engineersRemarks' => 'API probe test remarks',
            ],
        ];

        $this->info('Payload: '.json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $response = $this->authedRequest()
                ->post('/process/workflows/executeWorkflow', $payload);

            $this->logProbe('execute_workflow', '/process/workflows/executeWorkflow', 'POST', $response);

            $this->info("Response HTTP {$response->status()}:");
            $this->line(json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
        }
    }

    protected function probeGetWorkflowRuns(string $processId): void
    {
        $this->newLine();
        $this->info('=== Probe 8: Poll Workflow Runs ===');

        $workflowId = 'd702fb25-51ae-4d7f-88fc-132d555b2f00';

        // Probe 8a: Unfiltered (works, already confirmed)
        $this->info('  8a: Unfiltered getWorkflowRuns (page=1, perPageCount=3)');
        try {
            $response = $this->authedRequest()->get('/process/workflows/getWorkflowRuns', [
                'page' => 1,
                'perPageCount' => 3,
            ]);
            $this->logProbe('get_workflow_runs_unfiltered', '/process/workflows/getWorkflowRuns', 'GET', $response);
            $this->info("  Response HTTP {$response->status()}");
            $data = $response->json();
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Exception $e) {
            $this->warn("  Error: {$e->getMessage()}");
        }

        // Probe 8b: Try POST with filters body (Saras gRPC style)
        $this->newLine();
        $this->info('  8b: POST getWorkflowRuns with filters body');
        $filterPayloads = [
            ['filters' => ['workflowId' => $workflowId]],
            ['filters' => ['processId' => $processId]],
            ['filters' => ['workflowId_id' => $workflowId]],
            ['filters' => ['processId_id' => $processId]],
        ];

        foreach ($filterPayloads as $payload) {
            $this->info('    Filter: '.json_encode($payload));
            try {
                $response = $this->authedRequest()
                    ->post('/process/workflows/getWorkflowRuns', $payload);

                $key = 'get_workflow_runs_filtered_'.array_key_first($payload['filters']);
                $this->logProbe($key, '/process/workflows/getWorkflowRuns', 'POST', $response);

                $this->info("    Response HTTP {$response->status()}");
                $data = $response->json();
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (strlen($json) > 3000) {
                    $this->line(substr($json, 0, 3000)."\n    ... [truncated]");
                } else {
                    $this->line($json);
                }

                if ($response->successful() && ! empty($data['runs'])) {
                    $this->info('    ✓ Filtering works with: '.json_encode(array_keys($payload['filters'])));
                }
            } catch (\Exception $e) {
                $this->warn("    Error: {$e->getMessage()}");
            }
        }
    }

    protected function authedRequest(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson();
    }

    protected function logProbe(string $name, string $endpoint, string $method, $response): void
    {
        $this->results[$name] = [
            'endpoint' => $endpoint,
            'method' => $method,
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    protected function findInArray(array $data, string $needle): ?array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && stripos($value, $needle) !== false) {
                return is_array($data) ? $data : [$key => $value];
            }
            if (is_array($value)) {
                $found = $this->findInArray($value, $needle);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function saveResults(): void
    {
        $dir = base_path('tests/fixtures/saras');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = "{$dir}/probe_results_".date('Y-m-d_His').'.json';
        file_put_contents($path, json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info("Results saved to: {$path}");
    }
}
