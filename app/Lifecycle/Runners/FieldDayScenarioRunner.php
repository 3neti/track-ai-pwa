<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

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

        // Phase 4 + 5: Trigger Workflow + Poll (only if we have a saras_process_id)
        if (! empty($progressResult['saras_process_id'])) {
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

        $fixtureDir = base_path('tests/fixtures/images');
        $uploadedFileIds = [];
        $uploads = [];

        $files = [
            ['path' => "{$fixtureDir}/previous_progress.png", 'type' => 'previous_progress', 'label' => 'Previous Progress'],
            ['path' => "{$fixtureDir}/current_progress.png", 'type' => 'current_progress', 'label' => 'Current Progress'],
        ];

        foreach ($files as $fileSpec) {
            if (! file_exists($fileSpec['path'])) {
                if (! $context->output->isJson()) {
                    $context->output->warn("  ⚠ Fixture not found: {$fileSpec['path']}");
                }

                continue;
            }

            // Create Upload record
            $upload = $this->uploadService->createUploadRecord(
                userId: $context->user->id,
                contractId: $context->contractId,
                title: "{$fileSpec['label']} - Lifecycle",
                documentType: $fileSpec['type'],
                clientRequestId: (string) str()->uuid(),
                tags: ['lifecycle', 'progress', $fileSpec['type']],
                remarks: 'Uploaded via lifecycle scenario',
            );

            // Associate with project
            $upload->update(['project_id' => $context->project->id]);

            // Create UploadedFile from fixture
            $uploadedFile = new UploadedFile(
                path: $fileSpec['path'],
                originalName: basename($fileSpec['path']),
                mimeType: 'image/png',
                test: true,
            );

            // Upload to Saras
            $upload = $this->uploadService->uploadFileToRemote(
                upload: $upload,
                file: $uploadedFile,
                latitude: 14.5995,
                longitude: 120.9842,
                ipAddress: '127.0.0.1',
            );

            if ($upload->isUploaded() && $upload->remote_file_id) {
                $uploadedFileIds[$fileSpec['type']] = $upload->remote_file_id;

                if (! $context->output->isJson()) {
                    $context->output->info("  ✓ {$fileSpec['label']}: {$upload->remote_file_id}");
                }
            } else {
                if (! $context->output->isJson()) {
                    $context->output->warn("  ⚠ {$fileSpec['label']} upload failed: {$upload->last_error}");
                }
            }

            $uploads[] = [
                'id' => $upload->id,
                'status' => $upload->status,
                'remote_file_id' => $upload->remote_file_id,
                'entry_id' => $upload->entry_id,
                'type' => $fileSpec['type'],
            ];
        }

        return [
            'success' => ! empty($uploadedFileIds),
            'file_ids' => $uploadedFileIds,
            'uploads' => $uploads,
        ];
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
                'previous_progress_file_ids' => array_filter([$fileIds['previous_progress'] ?? null]),
                'current_progress_file_ids' => array_filter([$fileIds['current_progress'] ?? null]),
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
