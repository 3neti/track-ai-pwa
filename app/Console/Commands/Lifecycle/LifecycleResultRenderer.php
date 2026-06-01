<?php

declare(strict_types=1);

namespace App\Console\Commands\Lifecycle;

use App\Lifecycle\Output\SarasApiTracer;
use Illuminate\Console\Command;

final class LifecycleResultRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Command $command, array $payload, int $exitCode = 0, ?SarasApiTracer $tracer = null): int
    {
        if ($command->option('json')) {
            if ($tracer && $tracer->count() > 0) {
                $payload['_api_traces'] = array_map(fn ($t) => [
                    'method' => $t->method,
                    'endpoint' => $t->endpoint,
                    'status' => $t->status,
                    'duration_ms' => round($t->durationMs),
                    'request' => $t->requestSummary,
                    'response' => $t->responseSummary,
                ], $tracer->all());
            }

            $command->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $command->newLine();

        $success = $payload['success'] ?? false;

        if ($success) {
            $command->info('✓ Scenario completed successfully.');
        } else {
            $command->error('✗ Scenario failed.');
        }

        if (isset($payload['message'])) {
            $command->line("  Message: {$payload['message']}");
        }

        if (isset($payload['scenario'])) {
            $command->line("  Scenario: {$payload['scenario']}");
        }

        if (isset($payload['mode'])) {
            $command->line("  Mode: {$payload['mode']}");
        }

        if (isset($payload['outcome'])) {
            $command->line("  Outcome: {$payload['outcome']}");
        }

        if (isset($payload['polls'])) {
            $command->line("  Polls: {$payload['polls']}");
        }

        if (isset($payload['report'])) {
            $report = $payload['report'];
            $command->newLine();
            $command->line('  Report:');
            $command->line("    ID: {$report['id']}");
            $command->line("    Status: {$report['progress_status']}");

            if (! empty($report['saras_process_id'])) {
                $command->line("    Saras Process: {$report['saras_process_id']}");
            }

            if (! empty($report['saras_workflow_run_id'])) {
                $command->line("    Workflow Run: {$report['saras_workflow_run_id']}");
            }
        }

        // API Call Summary
        if ($tracer && $tracer->count() > 0) {
            $command->newLine();
            $command->line('═══ API Call Summary ═══');
            $command->line(sprintf(
                '  Total calls: %d | Total time: %ss',
                $tracer->count(),
                number_format($tracer->totalDurationMs() / 1000, 1),
            ));
            $command->line('  Endpoints:');

            foreach ($tracer->endpointCounts() as $endpoint => $count) {
                $label = $count === 1 ? '1 call' : "{$count} calls";
                $command->line("    {$endpoint} ... {$label}");
            }
        }

        return $exitCode;
    }
}
