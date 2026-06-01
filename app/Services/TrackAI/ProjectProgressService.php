<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Models\User;
use App\Services\Saras\DTO\WorkflowRunDTO;
use App\Services\TrackAI\Mappers\ProjectProgressWorkflowPayloadMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ProjectProgressService
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
        protected ProjectProgressWorkflowPayloadMapper $payloadMapper,
    ) {}

    /**
     * Check if progress sync to Saras is enabled.
     */
    protected function isProgressSyncEnabled(): bool
    {
        return config('saras.feature_flags.enabled', true)
            && config('saras.feature_flags.progress_enabled', false);
    }

    /**
     * Create a new ProjectProgress report and sync to Saras.
     *
     * @param  array{current_milestone?: string, remarks?: string, previous_progress_file_ids?: array<string>, current_progress_file_ids?: array<string>}  $input
     */
    public function createProgress(User $user, Project $project, array $input): ProjectProgressReport
    {
        $contractId = $project->contract_id ?: config('saras.default_contract_id');

        $report = ProjectProgressReport::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'contract_id' => $contractId,
            'current_milestone' => $input['current_milestone'] ?? null,
            'remarks' => $input['remarks'] ?? null,
            'previous_progress_file_ids' => $input['previous_progress_file_ids'] ?? [],
            'current_progress_file_ids' => $input['current_progress_file_ids'] ?? [],
            'progress_status' => ProjectProgressReport::STATUS_DRAFT,
        ]);

        if (! $this->isProgressSyncEnabled()) {
            AuditLog::log($user->id, 'project_progress_created_local', $contractId, [
                'report_id' => $report->id,
                'saras_sync' => false,
            ]);

            return $report;
        }

        try {
            $processResponse = $this->sarasClient->createProcess(
                subProjectId: config('saras.subproject_ids.project_progress'),
                fields: [
                    'contractId' => $contractId,
                    'currentMilestone' => $input['current_milestone'] ?? '',
                    'remarks' => $input['remarks'] ?? '',
                    'previousProgressFiles' => $input['previous_progress_file_ids'] ?? [],
                    'currentProgressFiles' => $input['current_progress_file_ids'] ?? [],
                    'geoLocation' => $input['geo_location'] ?? '',
                    'ipAddress' => $input['ip_address'] ?? '',
                    'date' => now()->toDateString(),
                    'time' => now()->toIso8601String(),
                    'name' => 'Progress Report - '.now()->toDateString(),
                    'tags' => ['progress', 'track-ai'],
                ],
            );

            if ($processResponse->success && $processResponse->processId) {
                $report->update([
                    'saras_process_id' => $processResponse->processId,
                    'progress_status' => ProjectProgressReport::STATUS_SUBMITTED,
                    'raw_saras_response' => $processResponse->toArray(),
                    'last_synced_at' => now(),
                ]);

                AuditLog::log($user->id, 'project_progress_created', $contractId, [
                    'report_id' => $report->id,
                    'saras_process_id' => $processResponse->processId,
                ]);
            }
        } catch (SarasApiException $e) {
            Log::error('ProjectProgress: Failed to create Saras process', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            AuditLog::log($user->id, 'project_progress_sync_failed', $contractId, [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $report->fresh();
    }

    /**
     * Trigger the completion workflow for a progress report.
     */
    public function triggerWorkflow(ProjectProgressReport $report): ProjectProgressReport
    {
        if (! $report->saras_process_id) {
            throw new \RuntimeException('Cannot trigger workflow: no Saras process ID');
        }

        $payload = $this->payloadMapper->map($report);

        try {
            $response = $this->sarasClient->executeWorkflow(
                workflowId: $payload['workflowId'],
                otherDetails: $payload['otherDetails'],
                payload: $payload['payload'],
            );

            // Extract runId from response - live API returns nested runId.id
            $runId = $response->executionId;

            $report->update([
                'saras_workflow_run_id' => $runId,
                'progress_status' => ProjectProgressReport::STATUS_PROCESSING,
                'last_synced_at' => now(),
            ]);

            AuditLog::log($report->user_id, 'project_progress_workflow_triggered', $report->contract_id, [
                'report_id' => $report->id,
                'workflow_run_id' => $runId,
            ]);

        } catch (SarasApiException $e) {
            Log::error('ProjectProgress: Failed to trigger workflow', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            $report->update([
                'progress_status' => ProjectProgressReport::STATUS_FAILED,
                'completion_status' => 'WORKFLOW_ERROR',
            ]);
        }

        return $report->fresh();
    }

    /**
     * Poll workflow status for a progress report.
     *
     * Since getWorkflowRuns has no server-side filtering, we paginate
     * and match by the stored saras_workflow_run_id.
     */
    public function pollWorkflowStatus(ProjectProgressReport $report): ?WorkflowRunDTO
    {
        if (! $report->saras_workflow_run_id) {
            return null;
        }

        try {
            // Paginate through runs to find our specific run
            $page = 1;
            $maxPages = 10;

            while ($page <= $maxPages) {
                $response = $this->sarasClient->getWorkflowRuns(page: $page, perPage: 20);

                $run = $response->findById($report->saras_workflow_run_id);
                if ($run) {
                    $this->updateStatusFromRun($report, $run);

                    return $run;
                }

                if ($page >= $response->totalPages) {
                    break;
                }

                $page++;
            }
        } catch (SarasApiException $e) {
            Log::error('ProjectProgress: Failed to poll workflow status', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get all progress reports for a project.
     *
     * @return Collection<int, ProjectProgressReport>
     */
    public function getProgressForProject(Project $project): Collection
    {
        return ProjectProgressReport::where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Update local report status based on workflow run state.
     */
    protected function updateStatusFromRun(ProjectProgressReport $report, WorkflowRunDTO $run): void
    {
        $newStatus = match ($run->state) {
            'SUCCESS' => ProjectProgressReport::STATUS_EVALUATED,
            'FAILED' => ProjectProgressReport::STATUS_FAILED,
            default => $report->progress_status,
        };

        if ($newStatus !== $report->progress_status) {
            $report->update([
                'progress_status' => $newStatus,
                'completion_status' => $run->state,
                'last_synced_at' => now(),
            ]);

            AuditLog::log($report->user_id, 'project_progress_status_updated', $report->contract_id, [
                'report_id' => $report->id,
                'old_status' => $report->progress_status,
                'new_status' => $newStatus,
                'workflow_state' => $run->state,
            ]);
        }
    }
}
