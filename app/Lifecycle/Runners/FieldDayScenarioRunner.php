<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

use App\Contracts\SarasClientInterface;
use App\Models\ProjectProgressReport;
use App\Models\Upload;
use App\Services\TrackAI\AttendanceService;
use App\Services\TrackAI\ProjectProgressService;
use App\Services\TrackAI\UploadService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

final class FieldDayScenarioRunner implements ScenarioRunnerContract
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly UploadService $uploadService,
        private readonly ProjectProgressService $progressService,
        private readonly SarasClientInterface $sarasClient,
    ) {}

    public function run(ScenarioRunContext $context): ScenarioRunResult
    {
        $payload = [
            'scenario' => $context->scenarioKey,
            'label' => $context->label(),
            'mode' => $context->mode(),
            'phases' => [],
            'user' => ['id' => $context->user->id, 'name' => $context->user->name],
            'project' => ['id' => $context->project->id, 'name' => $context->project->name],
        ];

        // Phase 0: Fetch contracts & milestones
        $contractsResult = $this->phaseFetchContracts($context);
        $payload['phases']['contracts'] = $contractsResult;

        // Phase 1: Attendance Check-In
        $checkInResult = $this->phaseCheckIn($context);
        $payload['phases']['check_in'] = $checkInResult;

        if (! $checkInResult['success']) {
            return $this->result($context, $payload, false);
        }

        // Phase 2: Upload Files
        $uploadResult = $this->phaseUploadFiles($context);
        $payload['phases']['upload'] = $uploadResult;

        // Phase 3: Submit Progress with file UUIDs
        $progressResult = $this->phaseSubmitProgress($context, $uploadResult);
        $payload['phases']['progress'] = $progressResult;

        if (! $progressResult['success']) {
            $this->phaseCheckOut($context, $payload);

            return $this->result($context, $payload, false);
        }

        // Phase 3.5: Attach stage files
        if (! empty($progressResult['saras_process_id'])) {
            $stageFilesResult = $this->phaseAttachStageFiles($context, $progressResult['report_id']);
            $payload['phases']['stage_files'] = $stageFilesResult;
        }

        // Phase 4 + 5: Trigger Workflow + Poll (only if we have a saras_process_id)
        if (! empty($progressResult['saras_process_id'])) {
            if (isset($stageFilesResult) && ! ($stageFilesResult['success'] ?? false)) {
                if (! $context->output->isJson()) {
                    $context->output->warn('  ⚠ Stage file attachment failed — workflow may not have stage checklist data');
                }
            }

            $workflowResult = $this->phaseWorkflow($context, $progressResult['report_id']);
            $payload['phases']['workflow'] = $workflowResult;
        }

        // Phase 6: Attendance Check-Out
        $checkOutResult = $this->phaseCheckOut($context, $payload);
        $payload['phases']['check_out'] = $checkOutResult;

        $payload['success'] = true;

        return $this->result($context, $payload, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseFetchContracts(ScenarioRunContext $context): array
    {
        if (! $context->output->isJson()) {
            $context->output->line('Phase 0: Fetching modules & contracts...');
        }

        try {
            $response = $this->sarasClient->getProjectsForUser(page: 1, perPage: 50);

            $modules = [];
            $selectedModule = null;

            foreach ($response->projects as $project) {
                $modules[] = [
                    'id' => $project->externalId,
                    'name' => $project->name,
                    'contract_id' => $project->contractId,
                ];

                if ($project->externalId === $context->contractId || $project->contractId === $context->contractId) {
                    $selectedModule = $project;
                }
            }

            if (! $context->output->isJson()) {
                $context->output->info('  ✓ '.count($modules).' module(s) available');

                foreach ($modules as $m) {
                    $marker = ($m['id'] === $context->contractId || $m['contract_id'] === $context->contractId) ? ' ← active' : '';
                    $context->output->line("    {$m['name']} ({$m['id']}){$marker}");
                }
            }

            // Fetch contracts from Contract AI subproject via getProcess (singular) + filters
            $contractEntries = [];
            $contractsAvailable = false;

            try {
                $contractAiId = 'acfdb45a-f4fd-4e25-8e52-de8ae6ff5b99';
                $contractResponse = $this->sarasClient->getProcesses($contractAiId, 1, 20);
                $contractsAvailable = true;

                foreach (($contractResponse['processes'] ?? []) as $c) {
                    $name = $c['fields']['legalName1'] ?? $c['metaDetails']['title'] ?? 'Contract #'.($c['metaDetails']['displayNumber'] ?? '?');
                    $milestones = $c['fields']['milestone'] ?? [];

                    $contractEntries[] = [
                        'id' => $c['id'] ?? '',
                        'name' => $name,
                        'milestones' => $milestones,
                        'display_number' => $c['metaDetails']['displayNumber'] ?? '',
                    ];
                }

                if (! $context->output->isJson()) {
                    $context->output->info('  ✓ '.count($contractEntries).' contract(s) available');

                    foreach ($contractEntries as $c) {
                        $cId = substr($c['id'], 0, 8);
                        $milestoneStr = ! empty($c['milestones']) ? implode(', ', $c['milestones']) : 'no milestones';
                        $context->output->line("    {$c['name']} ({$cId}...)");
                        $context->output->line("      Milestones: {$milestoneStr}");
                    }
                }
            } catch (\Exception $e) {
                if (! $context->output->isJson()) {
                    $context->output->warn('  ⚠ Contracts: '.$e->getMessage());
                }
            }

            return [
                'success' => true,
                'module_count' => count($modules),
                'modules' => $modules,
                'selected' => $selectedModule?->name,
                'contracts_available' => $contractsAvailable,
                'contract_count' => count($contractEntries),
                'contracts' => $contractEntries,
            ];
        } catch (\Exception $e) {
            if (! $context->output->isJson()) {
                $context->output->warn('  ⚠ Failed to fetch modules: '.$e->getMessage());
            }

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseCheckIn(ScenarioRunContext $context): array
    {
        if (! $context->output->isJson()) {
            $context->output->line('Phase 1: Attendance check-in...');
        }

        $result = $this->attendanceService->checkIn(
            user: $context->user,
            contractId: $context->contractId,
            latitude: 14.5995,
            longitude: 120.9842,
            remarks: 'Lifecycle scenario check-in',
            ipAddress: '127.0.0.1',
        );

        $success = $result['response']->success;
        $entryId = $result['response']->entryId;

        if (! $context->output->isJson()) {
            if ($success) {
                $context->output->info("  ✓ Checked in (entry: {$entryId})");
            } else {
                $context->output->warn("  ⚠ Check-in: {$result['response']->message}");
                // Already checked in is acceptable
                if ($result['attendance_status'] === 'checked_in') {
                    return ['success' => true, 'entry_id' => null, 'message' => 'Already checked in'];
                }
            }
        }

        return [
            'success' => $success || $result['attendance_status'] === 'checked_in',
            'entry_id' => $entryId,
            'status' => $result['attendance_status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseUploadFiles(ScenarioRunContext $context): array
    {
        if (! $context->output->isJson()) {
            $context->output->line('Phase 2: Uploading files to Saras...');
        }

        $bucketBase = $this->resolveBucketPath($context);
        $previousDir = $bucketBase.'/previous';
        $currentDir = $bucketBase.'/current';

        $previousFiles = $this->scanDirectory($previousDir);
        $currentFiles = $this->scanDirectory($currentDir);

        // Fallback to test fixtures if bucket is empty
        if (empty($previousFiles) && empty($currentFiles)) {
            $fixtureDir = base_path('tests/fixtures/images');
            $previousFiles = array_filter([is_file("{$fixtureDir}/previous_progress.png") ? "{$fixtureDir}/previous_progress.png" : null]);
            $currentFiles = array_filter([is_file("{$fixtureDir}/current_progress.png") ? "{$fixtureDir}/current_progress.png" : null]);

            if (! $context->output->isJson()) {
                $context->output->line('  (using test fixtures — bucket is empty)');
            }
        } else {
            if (! $context->output->isJson()) {
                $context->output->line("  Bucket: {$bucketBase}");
                $context->output->line(sprintf('  Found: %d previous, %d current files', count($previousFiles), count($currentFiles)));
            }
        }

        $uploadedFileIds = ['previous' => [], 'current' => []];
        $uploads = [];

        foreach ($previousFiles as $path) {
            $result = $this->uploadSingleFile($context, $path, 'previous_progress');
            $uploads[] = $result;

            if ($result['remote_file_id']) {
                $uploadedFileIds['previous'][] = $result['remote_file_id'];
            }
        }

        foreach ($currentFiles as $path) {
            $result = $this->uploadSingleFile($context, $path, 'current_progress');
            $uploads[] = $result;

            if ($result['remote_file_id']) {
                $uploadedFileIds['current'][] = $result['remote_file_id'];
            }
        }

        return [
            'success' => ! empty($uploadedFileIds['previous']) || ! empty($uploadedFileIds['current']),
            'file_ids' => $uploadedFileIds,
            'uploads' => $uploads,
        ];
    }

    private function resolveBucketPath(ScenarioRunContext $context): string
    {
        if ($context->bucket) {
            return rtrim($context->bucket, '/');
        }

        return storage_path('app/lifecycle/progress');
    }

    /**
     * @return array<string>
     */
    private function scanDirectory(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }

            $path = "{$dir}/{$entry}";

            if (is_file($path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadSingleFile(ScenarioRunContext $context, string $path, string $documentType): array
    {
        $filename = basename($path);
        $mime = mime_content_type($path) ?: 'application/octet-stream';

        $upload = $this->uploadService->createUploadRecord(
            userId: $context->user->id,
            contractId: $context->contractId,
            title: $filename,
            documentType: $documentType,
            clientRequestId: (string) str()->uuid(),
            tags: ['lifecycle', 'progress', $documentType],
            remarks: 'Uploaded via lifecycle scenario',
        );

        $upload->update(['project_id' => $context->project->id]);

        $uploadedFile = new UploadedFile(
            path: $path,
            originalName: $filename,
            mimeType: $mime,
            test: true,
        );

        $upload = $this->uploadService->uploadFileToRemote(
            upload: $upload,
            file: $uploadedFile,
            latitude: 14.5995,
            longitude: 120.9842,
            ipAddress: '127.0.0.1',
        );

        if ($upload->isUploaded() && $upload->remote_file_id) {
            if (! $context->output->isJson()) {
                $size = $this->formatSize((int) filesize($path));
                $context->output->info("  ✓ {$filename} ({$size}): {$upload->remote_file_id}");
            }
        } else {
            if (! $context->output->isJson()) {
                $context->output->warn("  ⚠ {$filename} upload failed: {$upload->last_error}");
            }
        }

        return [
            'id' => $upload->id,
            'filename' => $filename,
            'status' => $upload->status,
            'remote_file_id' => $upload->remote_file_id,
            'entry_id' => $upload->entry_id,
            'type' => $documentType,
        ];
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).'MB';
        }

        return number_format($bytes / 1024, 1).'KB';
    }

    /**
     * @param  array<string, mixed>  $uploadResult
     * @return array<string, mixed>
     */
    private function phaseSubmitProgress(ScenarioRunContext $context, array $uploadResult): array
    {
        if (! $context->output->isJson()) {
            $context->output->line('Phase 3: Submitting progress report...');
        }

        $fileIds = $uploadResult['file_ids'] ?? [];

        $report = $this->progressService->createProgress(
            user: $context->user,
            project: $context->project,
            input: [
                'current_milestone' => $context->currentMilestone(),
                'remarks' => $context->remarks(),
                'previous_progress_file_ids' => $fileIds['previous'] ?? [],
                'current_progress_file_ids' => $fileIds['current'] ?? [],
                'ip_address' => '127.0.0.1',
                'geo_location' => '14.5995,120.9842',
            ],
        );

        if (! $context->output->isJson()) {
            $context->output->info("  ✓ Report created (ID: {$report->id}, status: {$report->progress_status})");

            if ($report->saras_process_id) {
                $context->output->line("    Saras process: {$report->saras_process_id}");
            }
        }

        return [
            'success' => true,
            'report_id' => $report->id,
            'progress_status' => $report->progress_status,
            'saras_process_id' => $report->saras_process_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseAttachStageFiles(ScenarioRunContext $context, int $reportId): array
    {
        if (! $context->output->isJson()) {
            $context->output->line('Phase 3.5: Attaching stage files...');
        }

        $report = ProjectProgressReport::findOrFail($reportId);
        $result = $this->progressService->attachStageFiles($report);

        if (! $context->output->isJson()) {
            if ($result['success'] ?? false) {
                $prev = $result['previous'] ?? 0;
                $curr = $result['current'] ?? 0;

                if ($prev > 0) {
                    $context->output->info("  \u2713 previousProgressImages: {$prev} files attached");
                }

                if ($curr > 0) {
                    $context->output->info("  \u2713 currentProgressImages: {$curr} files attached");
                }

                $context->output->info("  \u2713 Stage updated (process: {$report->saras_process_id})");
            } else {
                $msg = $result['message'] ?? 'Unknown error';
                $context->output->warn("  \u26a0 Stage file attachment: {$msg}");
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseWorkflow(ScenarioRunContext $context, int $reportId): array
    {
        $report = ProjectProgressReport::findOrFail($reportId);

        // Phase 4: Trigger workflow
        if (! $context->output->isJson()) {
            $context->output->line('Phase 4: Triggering AI evaluation workflow...');
        }

        $report = $this->progressService->triggerWorkflow($report);

        if (! $report->isProcessing()) {
            if (! $context->output->isJson()) {
                $context->output->error('  ✗ Workflow trigger failed');
            }

            return ['success' => false, 'outcome' => 'workflow_trigger_failed'];
        }

        if (! $context->output->isJson()) {
            $context->output->info("  ✓ Workflow triggered (run: {$report->saras_workflow_run_id})");
            $context->output->line('Phase 5: Polling for completion...');
        }

        // Phase 5: Poll
        $pollCount = 0;

        while ($pollCount < $context->maxPolls) {
            $pollCount++;

            $run = $this->progressService->pollWorkflowStatus($report);
            $report->refresh();

            if (! $context->output->isJson()) {
                $state = $run ? $run->state : 'unknown';
                $context->output->line("  Poll {$pollCount}/{$context->maxPolls}: {$state}");

                // Enhanced failure diagnostics
                if ($run && $run->isFailed()) {
                    $context->output->line("    Workflow run: {$run->id}");
                    $context->output->line("    Flow state: {$run->flowState}");

                    $diagnostics = $run->diagnosticFields();
                    if (! empty($diagnostics)) {
                        foreach ($diagnostics as $key => $value) {
                            $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value;
                            $context->output->line("    {$key}: {$display}");
                        }
                    } else {
                        $context->output->line('    Failure details: not exposed by getWorkflowRuns response');
                    }

                    $context->output->line('    Next step: request full workflow run diagnostics from Saras');
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
            match ($finalStatus) {
                ProjectProgressReport::STATUS_EVALUATED => $context->output->info('  ✓ EVALUATED'),
                ProjectProgressReport::STATUS_FAILED => $context->output->error('  ✗ FAILED'),
                default => $context->output->warn("  ⚠ Incomplete: {$finalStatus}"),
            };
        }

        return [
            'success' => in_array($finalStatus, [ProjectProgressReport::STATUS_EVALUATED, ProjectProgressReport::STATUS_PROCESSING]),
            'outcome' => $finalStatus,
            'polls' => $pollCount,
            'workflow_run_id' => $report->saras_workflow_run_id,
            'completion_status' => $report->completion_status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseCheckOut(ScenarioRunContext $context, array &$payload): array
    {
        if (! $context->output->isJson()) {
            $context->output->line('Phase 6: Attendance check-out...');
        }

        $result = $this->attendanceService->checkOut(
            user: $context->user,
            contractId: $context->contractId,
            latitude: 14.5995,
            longitude: 120.9842,
            remarks: 'Lifecycle scenario check-out',
            ipAddress: '127.0.0.1',
        );

        $success = $result['response']->success;

        if (! $context->output->isJson()) {
            if ($success) {
                $context->output->info("  ✓ Checked out (entry: {$result['response']->entryId})");
            } else {
                $context->output->warn("  ⚠ Check-out: {$result['response']->message}");
            }
        }

        return [
            'success' => $success,
            'entry_id' => $result['response']->entryId,
            'status' => $result['attendance_status'],
        ];
    }

    private function result(ScenarioRunContext $context, array $payload, bool $success): ScenarioRunResult
    {
        $payload['success'] = $success;

        return new ScenarioRunResult(
            exitCode: $success ? Command::SUCCESS : Command::FAILURE,
            payload: $payload,
        );
    }
}
