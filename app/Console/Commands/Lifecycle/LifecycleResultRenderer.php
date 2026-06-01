<?php

declare(strict_types=1);

namespace App\Console\Commands\Lifecycle;

use Illuminate\Console\Command;

final class LifecycleResultRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Command $command, array $payload, int $exitCode = 0): int
    {
        if ($command->option('json')) {
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

        return $exitCode;
    }
}
