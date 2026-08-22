<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Console\Command;

final class DefaultProgressScenarioRunner implements ScenarioRunnerContract
{
    public function __construct(
        private readonly ProjectProgressService $progressService,
    ) {}

    public function run(ScenarioRunContext $context): ScenarioRunResult
    {
        if (! $context->output->isJson()) {
            $context->output->line('Creating progress report...');
        }

        $report = $this->progressService->createProgress(
            user: $context->user,
            project: $context->project,
            input: [
                'contract_id' => $context->contractId,
                'current_milestone' => $context->currentMilestone(),
                'remarks' => $context->remarks(),
            ],
        );

        if (! $context->output->isJson()) {
            $context->output->info("Progress report created (ID: {$report->id}, status: {$report->progress_status})");

            if ($report->saras_process_id) {
                $context->output->line("Saras process ID: {$report->saras_process_id}");
            }
        }

        return new ScenarioRunResult(
            exitCode: Command::SUCCESS,
            payload: [
                'success' => true,
                'scenario' => $context->scenarioKey,
                'label' => $context->label(),
                'mode' => $context->mode(),
                'report' => [
                    'id' => $report->id,
                    'progress_status' => $report->progress_status,
                    'saras_process_id' => $report->saras_process_id,
                    'current_milestone' => $report->current_milestone,
                    'remarks' => $report->remarks,
                ],
                'user' => ['id' => $context->user->id, 'name' => $context->user->name],
                'project' => ['id' => $context->project->id, 'name' => $context->project->name],
            ],
        );
    }
}
