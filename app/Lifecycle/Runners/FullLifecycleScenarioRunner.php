<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

use App\Models\ProjectProgressReport;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Console\Command;

final class FullLifecycleScenarioRunner implements ScenarioRunnerContract
{
    public function __construct(
        private readonly ProjectProgressService $progressService,
    ) {}

    public function run(ScenarioRunContext $context): ScenarioRunResult
    {
        // Phase 1: Create progress report
        if (! $context->output->isJson()) {
            $context->output->line('Phase 1: Creating progress report...');
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

        if (! $report->saras_process_id) {
            if (! $context->output->isJson()) {
                $context->output->warn('Progress report created locally only (Saras sync disabled or failed).');
            }

            return $this->buildResult($context, $report, 'submitted_local');
        }

        if (! $context->output->isJson()) {
            $context->output->info("Progress report submitted (Saras ID: {$report->saras_process_id})");
        }

        // Phase 2: Wait for the workflow Saras automatically starts for the process.
        if (! $context->output->isJson()) {
            $context->output->line('Phase 2: Waiting for automatic AI evaluation...');
        }

        // Phase 3: Discover and poll the automatically created workflow run.
        $pollCount = 0;

        while ($pollCount < $context->maxPolls) {
            $pollCount++;

            $run = $this->progressService->pollWorkflowStatus($report);
            $report->refresh();

            if (! $context->output->isJson()) {
                $status = $run ? $run->state : 'unknown';
                $context->output->line("  Poll {$pollCount}/{$context->maxPolls}: {$status}");

                if ($run && $pollCount === 1) {
                    $context->output->info("  Automatic workflow found (run ID: {$run->id})");
                }
            }

            if ($report->isTerminal()) {
                break;
            }

            if ($pollCount < $context->maxPolls) {
                sleep($context->poll);
            }
        }

        $finalStatus = $report->progress_status;

        if (! $context->output->isJson()) {
            if ($finalStatus === ProjectProgressReport::STATUS_EVALUATED) {
                $context->output->info('Lifecycle complete: EVALUATED');
            } elseif ($finalStatus === ProjectProgressReport::STATUS_FAILED) {
                $context->output->error('Lifecycle complete: FAILED');
            } else {
                $context->output->warn("Lifecycle incomplete after {$pollCount} polls: {$finalStatus}");
            }
        }

        return $this->buildResult($context, $report, $finalStatus, $pollCount);
    }

    private function buildResult(
        ScenarioRunContext $context,
        ProjectProgressReport $report,
        string $outcome,
        int $pollCount = 0,
    ): ScenarioRunResult {
        $success = in_array($outcome, [
            ProjectProgressReport::STATUS_EVALUATED,
            ProjectProgressReport::STATUS_PROCESSING,
            'submitted_local',
        ]);

        return new ScenarioRunResult(
            exitCode: $success ? Command::SUCCESS : Command::FAILURE,
            payload: [
                'success' => $success,
                'scenario' => $context->scenarioKey,
                'label' => $context->label(),
                'mode' => $context->mode(),
                'outcome' => $outcome,
                'polls' => $pollCount,
                'report' => [
                    'id' => $report->id,
                    'progress_status' => $report->progress_status,
                    'completion_status' => $report->completion_status,
                    'saras_process_id' => $report->saras_process_id,
                    'saras_workflow_run_id' => $report->saras_workflow_run_id,
                    'current_milestone' => $report->current_milestone,
                ],
                'user' => ['id' => $context->user->id, 'name' => $context->user->name],
                'project' => ['id' => $context->project->id, 'name' => $context->project->name],
            ],
        );
    }
}
