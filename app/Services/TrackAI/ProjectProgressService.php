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
     * Find the latest progress report for a contract/milestone combination.
     */
    public function findLatestProgressForContractMilestone(
        string $contractId,
        string $milestone,
    ): ?ProjectProgressReport {
        return ProjectProgressReport::where('contract_id', $contractId)
            ->where('current_milestone', $milestone)
            ->whereNotNull('current_progress_file_ids')
            ->where('progress_status', '!=', ProjectProgressReport::STATUS_DRAFT)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Resolve previous progress file IDs from the last submitted report.
     *
     * @return array<string>
     */
    public function resolvePreviousProgressFileIds(
        string $contractId,
        string $milestone,
    ): array {
        $latest = $this->findLatestProgressForContractMilestone($contractId, $milestone);

        if (! $latest) {
            return [];
        }

        $fileIds = $latest->current_progress_file_ids ?? [];

        return ! empty($fileIds) ? $fileIds : [];
    }

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
        $contractId = $input['contract_id'] ?? $project->contract_id ?: config('saras.default_contract_id');
        $milestone = $input['current_milestone'] ?? '';

        // Auto-resolve previous progress file IDs if not explicitly provided
        $previousFileIds = $input['previous_progress_file_ids'] ?? [];

        if (empty($previousFileIds) && $contractId && $milestone) {
            $previousFileIds = $this->resolvePreviousProgressFileIds($contractId, $milestone);
        }

        $report = ProjectProgressReport::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'contract_id' => $contractId,
            'current_milestone' => $milestone ?: null,
            'remarks' => $input['remarks'] ?? null,
            'previous_progress_file_ids' => $previousFileIds,
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
                    'previousProgressFiles' => $previousFileIds,
                    'currentProgressFiles' => $input['current_progress_file_ids'] ?? [],
                    'geoLocation' => $input['geo_location'] ?? '',
                    'ipAddress' => $input['ip_address'] ?? '',
                    'date' => now('Asia/Manila')->toDateString(),
                    'time' => now('Asia/Manila')->toIso8601String(),
                    'name' => 'Progress Report - '.now('Asia/Manila')->toDateString(),
                    'tags' => ! empty($input['tags'])
                        ? array_values($input['tags'])
                        : ['progress', 'track-ai'],
                ],
                parentProcessId: $contractId,
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
     * Attach files to the workflow stage checklist via /process/updateFiles.
     *
     * @return array<string, mixed>
     */
    public function attachStageFiles(ProjectProgressReport $report): array
    {
        if (! $report->saras_process_id) {
            return ['success' => false, 'message' => 'No Saras process ID'];
        }

        $previousIds = $report->previous_progress_file_ids ?? [];
        $currentIds = $report->current_progress_file_ids ?? [];

        if (empty($previousIds) && empty($currentIds)) {
            return ['success' => true, 'message' => 'No files to attach', 'previous' => 0, 'current' => 0];
        }

        $files = [];

        if (! empty($previousIds)) {
            $files['previousProgressImages'] = ['fileIds' => $previousIds];
        }

        if (! empty($currentIds)) {
            $files['currentProgressImages'] = ['fileIds' => $currentIds];
        }

        try {
            $response = $this->sarasClient->updateFiles(
                processId: $report->saras_process_id,
                stageKey: config('saras.workflows.completion_stage_key'),
                subProjectId: config('saras.subproject_ids.project_progress'),
                files: $files,
            );

            AuditLog::log($report->user_id, 'project_progress_stage_files_attached', $report->contract_id, [
                'report_id' => $report->id,
                'saras_process_id' => $report->saras_process_id,
                'previous_count' => count($previousIds),
                'current_count' => count($currentIds),
            ]);

            return [
                'success' => true,
                'previous' => count($previousIds),
                'current' => count($currentIds),
                'response' => $response,
            ];
        } catch (SarasApiException $e) {
            Log::error('ProjectProgress: Failed to attach stage files', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
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
     * Uses server-side filtering by processId + workflowId for direct lookup.
     */
    public function pollWorkflowStatus(ProjectProgressReport $report): ?WorkflowRunDTO
    {
        if (! $report->saras_process_id) {
            return null;
        }

        try {
            $response = $this->sarasClient->getWorkflowRuns(
                page: 1,
                perPage: 5,
                filters: [
                    'subProjectId' => config('saras.subproject_ids.project_progress'),
                    'processId' => $report->saras_process_id,
                    'workflowId' => config('saras.workflows.completion_id'),
                ],
            );

            $run = $report->saras_workflow_run_id
                ? $response->findById($report->saras_workflow_run_id)
                : ($response->runs[0] ?? null);

            if ($run) {
                if (! $report->saras_workflow_run_id) {
                    $report->update([
                        'saras_workflow_run_id' => $run->id,
                        'progress_status' => ProjectProgressReport::STATUS_PROCESSING,
                        'last_synced_at' => now(),
                    ]);
                }

                $this->updateStatusFromRun($report, $run);

                return $run;
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
