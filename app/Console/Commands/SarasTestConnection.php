<?php

namespace App\Console\Commands;

use App\Contracts\SarasClientInterface;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SarasTestConnection extends Command
{
    protected $signature = 'saras:test
                            {--fresh : Force a fresh token (ignore cache)}
                            {--user : Also fetch user details}
                            {--projects : Also fetch projects}';

    protected $description = 'Test Saras API connection and credentials';

    public function handle(SarasClientInterface $client): int
    {
        $mode = config('saras.mode');
        $baseUrl = config('saras.base_url');
        $username = config('saras.username');
        $password = config('saras.password');

        $this->components->info('Saras API Connection Test');
        $this->newLine();

        // ─── Configuration ───
        $this->components->twoColumnDetail('<fg=cyan>Configuration</>', '');
        $this->components->twoColumnDetail('Mode', $mode === 'stub' ? '<fg=yellow>stub</>' : '<fg=green>live</>');
        $this->components->twoColumnDetail('Base URL', "<fg=white>{$baseUrl}</>");
        $this->components->twoColumnDetail('Username', $username ? "<fg=green>{$username}</>" : '<fg=red>missing</>');
        $this->components->twoColumnDetail('Password', $password ? '<fg=green>'.Str::mask($password, '*', 3).'</>' : '<fg=red>missing</>');

        $this->newLine();

        // ─── SubProject IDs ───
        $this->components->twoColumnDetail('<fg=cyan>SubProject IDs</>', '');
        $this->components->twoColumnDetail('Attendance', config('saras.subproject_ids.attendance') ?: '<fg=yellow>not set</>');
        $this->components->twoColumnDetail('TrackData', config('saras.subproject_ids.trackdata') ?: '<fg=yellow>not set</>');
        $this->components->twoColumnDetail('Progress', config('saras.subproject_ids.progress') ?: '<fg=yellow>not set</>');
        $this->components->twoColumnDetail('Workflow ID', config('saras.workflow_id') ?: '<fg=yellow>not set</>');

        $this->newLine();

        if ($mode === 'stub') {
            $this->components->warn('Running in stub mode - no actual API calls will be made.');
            $this->components->info('Set SARAS_MODE=live in .env to test real connection.');

            return self::SUCCESS;
        }

        // Check for missing credentials
        if (! $username || ! $password) {
            $this->components->error('Missing credentials. Set SARAS_USERNAME and SARAS_PASSWORD in .env');

            return self::FAILURE;
        }

        // Clear cache if --fresh
        if ($this->option('fresh')) {
            Cache::forget(config('saras.token_cache_key', 'saras:token'));
            $this->components->info('Cleared cached token.');
            $this->newLine();
        }

        // ─── Test 1: Authentication ───
        $this->line('<fg=cyan>━━━ Test 1: Authentication ━━━</>');
        $this->newLine();

        $endpoint = $baseUrl.'/users/userLogin';
        $payload = [
            'client_id' => $username,
            'client_secret' => $password,
        ];

        $this->components->twoColumnDetail('Endpoint', "<fg=blue>POST</> {$endpoint}");
        $this->line('<fg=gray>Payload:</>');
        $this->line('<fg=gray>'.json_encode([
            'client_id' => $username,
            'client_secret' => Str::mask($password, '*', 3),
        ], JSON_PRETTY_PRINT).'</>');
        $this->newLine();

        $token = null;
        try {
            $response = Http::timeout(config('saras.timeout', 30))
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payload);

            $this->components->twoColumnDetail('Status', $response->successful()
                ? '<fg=green>'.$response->status().' OK</>'
                : '<fg=red>'.$response->status().' FAILED</>');

            $data = $response->json();

            if ($response->successful()) {
                $token = $data['access_token'] ?? $data['token'] ?? null;
                $expiresIn = $data['expires_in'] ?? $data['expiresIn'] ?? 'unknown';

                $this->line('<fg=gray>Response:</>');
                $this->line('<fg=green>'.json_encode([
                    'access_token' => $token ? Str::limit($token, 40).'...' : null,
                    'expires_in' => $expiresIn,
                ], JSON_PRETTY_PRINT).'</>');

                if ($token) {
                    $this->newLine();
                    $this->components->info('✓ Authentication successful!');
                } else {
                    $this->newLine();
                    $this->components->error('No access_token in response');
                    $this->line('<fg=red>Full response: '.json_encode($data, JSON_PRETTY_PRINT).'</>');

                    return self::FAILURE;
                }
            } else {
                $this->line('<fg=red>Response: '.json_encode($data, JSON_PRETTY_PRINT).'</>');

                return self::FAILURE;
            }
        } catch (ConnectionException $e) {
            $this->components->error('Connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // ─── Test 2: User Details (optional) ───
        if ($this->option('user') && $token) {
            $this->newLine();
            $this->line('<fg=cyan>━━━ Test 2: User Details ━━━</>');
            $this->newLine();

            $endpoint = $baseUrl.'/users/getUserDetails';
            $this->components->twoColumnDetail('Endpoint', "<fg=blue>GET</> {$endpoint}");
            $this->components->twoColumnDetail('Authorization', 'Bearer '.Str::limit($token, 20).'...');
            $this->newLine();

            try {
                $response = Http::timeout(config('saras.timeout', 30))
                    ->withToken($token)
                    ->acceptJson()
                    ->get($endpoint);

                $this->components->twoColumnDetail('Status', $response->successful()
                    ? '<fg=green>'.$response->status().' OK</>'
                    : '<fg=red>'.$response->status().' FAILED</>');

                $data = $response->json();
                $this->line('<fg=gray>Response:</>');
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if ($response->successful()) {
                    $this->newLine();
                    $this->components->info('✓ User details fetched!');
                }
            } catch (ConnectionException $e) {
                $this->components->error('Connection failed: '.$e->getMessage());
            }
        }

        // ─── Test 3: Projects (optional) ───
        if ($this->option('projects') && $token) {
            $this->newLine();
            $this->line('<fg=cyan>━━━ Test 3: Projects ━━━</>');
            $this->newLine();

            $endpoint = $baseUrl.'/process/projects/getProjectsForUser?page=1&perPageCount=5';
            $this->components->twoColumnDetail('Endpoint', "<fg=blue>GET</> {$endpoint}");
            $this->components->twoColumnDetail('Authorization', 'Bearer '.Str::limit($token, 20).'...');
            $this->newLine();

            try {
                $response = Http::timeout(config('saras.timeout', 30))
                    ->withToken($token)
                    ->acceptJson()
                    ->get($endpoint);

                $this->components->twoColumnDetail('Status', $response->successful()
                    ? '<fg=green>'.$response->status().' OK</>'
                    : '<fg=red>'.$response->status().' FAILED</>');

                $data = $response->json();
                $this->line('<fg=gray>Response:</>');
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if ($response->successful()) {
                    $this->newLine();
                    $this->components->info('✓ Projects fetched!');
                }
            } catch (ConnectionException $e) {
                $this->components->error('Connection failed: '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->components->success('Connection test completed!');

        return self::SUCCESS;
    }
}
