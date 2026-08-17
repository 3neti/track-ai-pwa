<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Models\User;
use App\Services\Saras\DTO\WorkflowRunDTO;
use App\Services\TrackAI\Mappers\ProjectProgressWorkflowPayloadMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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
            ->whereNull('remote_deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (ProjectProgressReport $report): bool => ! empty($report->current_progress_file_ids ?? []));
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

    public function progressSubmissionBlocker(string $contractId, string $milestone): ?string
    {
        $orderBlocker = $this->milestoneOrderBlocker($contractId, $milestone);

        if ($orderBlocker !== null) {
            return $orderBlocker;
        }

        if ($this->isMilestoneLockedForProgress($contractId, $milestone)) {
            return 'This milestone is already in progress and cannot be edited.';
        }

        return null;
    }

    public function uploadSubmissionBlocker(string $contractId, string $milestone): ?string
    {
        $blocker = $this->progressSubmissionBlocker($contractId, $milestone);

        if ($blocker === 'This milestone is already in progress and cannot be edited.') {
            return 'This milestone is already in progress and cannot accept new uploads.';
        }

        return $blocker;
    }

    /**
     * Determine whether a milestone already has active progress and should no longer be edited.
     */
    public function isMilestoneLockedForProgress(string $contractId, string $milestone): bool
    {
        return ProjectProgressReport::where('contract_id', $contractId)
            ->where('current_milestone', $milestone)
            ->whereNotIn('progress_status', [
                ProjectProgressReport::STATUS_DRAFT,
                ProjectProgressReport::STATUS_FAILED,
            ])
            ->whereNull('certificate_file_id')
            ->whereNull('remote_deleted_at')
            ->exists();
    }

    protected function milestoneOrderBlocker(string $contractId, string $milestone): ?string
    {
        $milestones = Contract::where('saras_process_id', $contractId)->first()?->milestones;

        if (! is_array($milestones) || $milestones === []) {
            return null;
        }

        $milestones = array_values(array_filter($milestones, fn (mixed $value): bool => is_string($value) && $value !== ''));
        $targetIndex = array_search($milestone, $milestones, true);

        if ($targetIndex === false) {
            return null;
        }

        for ($index = 0; $index < $targetIndex; $index++) {
            $previousMilestone = $milestones[$index];

            if (! $this->hasMilestoneProgressStarted($contractId, $previousMilestone)) {
                return "Submit {$previousMilestone} before submitting {$milestone}.";
            }
        }

        return null;
    }

    protected function hasMilestoneProgressStarted(string $contractId, string $milestone): bool
    {
        $hasLocalProgress = ProjectProgressReport::where('contract_id', $contractId)
            ->where('current_milestone', $milestone)
            ->whereNotIn('progress_status', [
                ProjectProgressReport::STATUS_DRAFT,
                ProjectProgressReport::STATUS_FAILED,
            ])
            ->whereNull('remote_deleted_at')
            ->exists();

        return $hasLocalProgress;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function remoteProgressProcesses(): array
    {
        try {
            $response = $this->sarasClient->getProcesses(
                config('saras.subproject_ids.project_progress'),
                1,
                50,
            );

            return $response['processes'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('ProjectProgress: Unable to check remote progress records', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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
     * Sync Saras ProjectProgress records into the local database as cache rows.
     *
     * @return Collection<int, ProjectProgressReport>
     */
    public function syncProjectProgressFromSaras(User $user, Project $project, bool $pruneMissing = true): Collection
    {
        if (! $this->isProgressSyncEnabled() || $this->sarasClient->isStubMode()) {
            return new Collection;
        }

        $processes = $this->fetchAllRemoteProgressProcesses();
        $syncedProcessIds = [];

        foreach ($processes as $process) {
            $processId = $process['id'] ?? null;

            if (! is_string($processId) || $processId === '') {
                continue;
            }

            $fields = is_array($process['fields'] ?? null) ? $process['fields'] : [];
            $contractId = $this->normalizeProcessReference($fields['contractId'] ?? null);
            $milestone = $this->normalizeProcessReference($fields['currentMilestone'] ?? null);

            if ($contractId === null || $milestone === null) {
                continue;
            }

            $syncedProcessIds[] = $processId;
            $certificateFileId = $this->normalizeFirstFileId($fields['certificateOfCompletion'] ?? null);

            ProjectProgressReport::updateOrCreate(
                ['saras_process_id' => $processId],
                [
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'contract_id' => $contractId,
                    'current_milestone' => $milestone,
                    'remarks' => is_string($fields['remarks'] ?? null) ? $fields['remarks'] : null,
                    'previous_progress_file_ids' => $this->normalizeFileIds($fields['previousProgressFiles'] ?? []),
                    'current_progress_file_ids' => $this->normalizeFileIds($fields['currentProgressFiles'] ?? []),
                    'progress_status' => $certificateFileId
                        ? ProjectProgressReport::STATUS_EVALUATED
                        : ProjectProgressReport::STATUS_SUBMITTED,
                    'completion_status' => $certificateFileId ? 'SUCCESS' : null,
                    'certificate_file_id' => $certificateFileId,
                    'raw_saras_response' => $process,
                    'source' => ProjectProgressReport::SOURCE_SARAS,
                    'remote_deleted_at' => null,
                    'last_synced_at' => now(),
                    'created_at' => $this->parseRemoteTimestamp($process) ?? now(),
                ],
            );
        }

        if ($pruneMissing) {
            ProjectProgressReport::where('source', ProjectProgressReport::SOURCE_SARAS)
                ->whereNull('remote_deleted_at')
                ->whereNotNull('saras_process_id')
                ->when(
                    $syncedProcessIds !== [],
                    fn ($query) => $query->whereNotIn('saras_process_id', $syncedProcessIds),
                    fn ($query) => $query
                )
                ->update([
                    'remote_deleted_at' => now(),
                    'progress_status' => ProjectProgressReport::STATUS_FAILED,
                    'last_synced_at' => now(),
                ]);
        }

        return ProjectProgressReport::where('source', ProjectProgressReport::SOURCE_SARAS)
            ->whereNull('remote_deleted_at')
            ->whereIn('saras_process_id', $syncedProcessIds)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAllRemoteProgressProcesses(): array
    {
        $page = 1;
        $perPage = 50;
        $processes = [];

        do {
            $response = $this->sarasClient->getProcesses(
                config('saras.subproject_ids.project_progress'),
                $page,
                $perPage,
            );

            $processes = array_merge($processes, $response['processes'] ?? []);
            $totalPages = (int) ($response['meta']['totalPages'] ?? $response['totalPages'] ?? $page);
            $page++;
        } while ($page <= max(1, $totalPages));

        return $processes;
    }

    protected function normalizeProcessReference(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $id = $value['id'] ?? $value['value'] ?? $value['title'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * @return array<string>
     */
    protected function normalizeFileIds(mixed $value): array
    {
        if (! is_array($value)) {
            return is_string($value) && $value !== '' ? [$value] : [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->normalizeFirstFileId($item),
            $value,
        )));
    }

    protected function normalizeFirstFileId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            if (isset($value['id']) && is_string($value['id']) && $value['id'] !== '') {
                return $value['id'];
            }

            if (array_is_list($value)) {
                return $this->normalizeFirstFileId($value[0] ?? null);
            }
        }

        return null;
    }

    protected function parseRemoteTimestamp(array $process): ?Carbon
    {
        $timestamp = $process['createdTs']
            ?? $process['createdAt']
            ?? $process['created_at']
            ?? null;

        return is_string($timestamp) && $timestamp !== ''
            ? Carbon::parse($timestamp)
            : null;
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

        $previousFileIds = $contractId && $milestone
            ? $this->resolvePreviousProgressFileIds($contractId, $milestone)
            : [];

        $report = ProjectProgressReport::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'contract_id' => $contractId,
            'current_milestone' => $milestone ?: null,
            'remarks' => $input['remarks'] ?? null,
            'previous_progress_file_ids' => $previousFileIds,
            'current_progress_file_ids' => $input['current_progress_file_ids'] ?? [],
            'location_status' => $input['location_assessment']['status'] ?? null,
            'location_evidence' => $input['location_assessment']['evidence'] ?? null,
            'progress_status' => ProjectProgressReport::STATUS_DRAFT,
            'source' => ProjectProgressReport::SOURCE_LOCAL,
        ]);

        if (! $this->isProgressSyncEnabled()) {
            AuditLog::log($user->id, 'project_progress_created_local', $contractId, [
                'report_id' => $report->id,
                'saras_sync' => false,
            ]);

            return $report;
        }

        try {
            $fields = [
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
            ];

            if (config('saras.location_trust.send_to_saras', false)) {
                $fields += [
                    'geoAccuracy' => $input['location_assessment']['evidence']['accuracy_meters'] ?? '',
                    'locationTrust' => $input['location_assessment']['status'] ?? '',
                    'locationTrustReasons' => implode(',', $input['location_assessment']['reasons'] ?? []),
                ];
            }

            $processResponse = $this->sarasClient->createProcess(
                subProjectId: config('saras.subproject_ids.project_progress'),
                fields: $fields,
                parentProcessId: $contractId,
            );

            if ($processResponse->success && $processResponse->processId) {
                $report->update([
                    'saras_process_id' => $processResponse->processId,
                    'progress_status' => ProjectProgressReport::STATUS_SUBMITTED,
                    'source' => ProjectProgressReport::SOURCE_SARAS,
                    'remote_deleted_at' => null,
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

            $updates = [
                'progress_status' => ProjectProgressReport::STATUS_PROCESSING,
                'completion_status' => $runId ? $response->status : 'WORKFLOW_TRIGGERED_NO_RUN_ID',
                'last_synced_at' => now(),
            ];

            if ($runId) {
                $updates['saras_workflow_run_id'] = $runId;
            }

            $report->update($updates);

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
            $run = $this->findWorkflowRun($report);

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

            if ($this->shouldTriggerMissingWorkflowRun($report)) {
                Log::warning('ProjectProgress: No Saras workflow run found; triggering explicit workflow fallback', [
                    'report_id' => $report->id,
                    'process_id' => $report->saras_process_id,
                    'workflow_id' => config('saras.workflows.completion_id'),
                ]);

                $report = $this->triggerWorkflow($report);
                $run = $this->findWorkflowRun($report);

                if ($run) {
                    $this->updateStatusFromRun($report, $run);

                    return $run;
                }
            }
        } catch (SarasApiException $e) {
            Log::error('ProjectProgress: Failed to poll workflow status', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function findWorkflowRun(ProjectProgressReport $report): ?WorkflowRunDTO
    {
        $filters = [
            'subProjectId' => config('saras.subproject_ids.project_progress'),
            'processId' => $report->saras_process_id,
            'workflowId' => config('saras.workflows.completion_id'),
        ];

        if ($report->saras_workflow_run_id) {
            $filters['runId'] = $report->saras_workflow_run_id;
        }

        $response = $this->sarasClient->getWorkflowRuns(
            page: 1,
            perPage: 5,
            filters: $filters,
        );

        return $report->saras_workflow_run_id
            ? $response->findById($report->saras_workflow_run_id)
            : ($response->runs[0] ?? null);
    }

    protected function shouldTriggerMissingWorkflowRun(ProjectProgressReport $report): bool
    {
        return (bool) config('saras.workflows.trigger_missing_run_on_poll', true)
            && ! $report->saras_workflow_run_id
            && $report->isSubmitted();
    }

    /**
     * Get all progress reports for a project.
     *
     * @return Collection<int, ProjectProgressReport>
     */
    public function getProgressForProject(Project $project): Collection
    {
        return ProjectProgressReport::where('project_id', $project->id)
            ->whereNull('remote_deleted_at')
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
