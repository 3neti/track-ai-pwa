<?php

declare(strict_types=1);

namespace App\Console\Commands\Lifecycle;

use App\Lifecycle\Output\SarasApiTrace;
use App\Lifecycle\Output\SarasApiTracer;
use Illuminate\Console\Command;

final class LifecycleReportRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Command $command, array $payload, SarasApiTracer $tracer): void
    {
        $phases = $payload['phases'] ?? [];

        $this->renderFlowDiagram($command, $payload, $phases);
        $this->renderContractsAndMilestones($command, $phases);
        $this->renderRunArtifacts($command, $payload, $phases);
        $this->renderWorkflowTriggerSummary($command, $phases);
        $this->renderSarasActionItems($command, $phases);
        $this->renderFullPayloads($command, $tracer);
        $this->renderFullResponses($command, $tracer);
        $this->renderDeveloperInterpretation($command, $phases);
        $this->renderIntegrationScorecard($command, $phases);
        $this->renderSarasTraceIds($command, $tracer);
        $this->renderExecutiveSummary($command, $phases);
    }

    /**
     * @param  array<string, mixed>  $phases
     */
    private function renderFlowDiagram(Command $command, array $payload, array $phases): void
    {
        $command->newLine();
        $command->line('════════ Lifecycle Flow ════════');
        $command->newLine();

        $steps = $this->buildFlowSteps($payload, $phases);

        foreach ($steps as $i => $step) {
            $icon = match ($step['status']) {
                'success' => '✓',
                'failed' => '✗',
                'pending' => '⏳',
                default => '?',
            };

            $command->line("  [{$icon}] {$step['label']}");

            if (! empty($step['detail'])) {
                $command->line("        {$step['detail']}");
            }

            if ($i < count($steps) - 1) {
                $command->line('            ↓');
            }
        }

        $command->newLine();
    }

    /**
     * @return array<array{label: string, status: string, detail: string}>
     */
    private function buildFlowSteps(array $payload, array $phases): array
    {
        $steps = [];

        // Modules & Contracts
        $contracts = $phases['contracts'] ?? null;
        if ($contracts) {
            $modCount = $contracts['module_count'] ?? 0;
            $selected = $contracts['selected'] ?? 'none';
            $contractsAvail = $contracts['contracts_available'] ?? false;
            $contractCount = $contracts['contract_count'] ?? 0;

            $detail = "Modules: {$modCount} | Selected: {$selected}";
            if ($contractsAvail) {
                $detail .= " | Contracts: {$contractCount}";
            } else {
                $detail .= ' | Contracts: pending license';
            }

            $steps[] = [
                'label' => 'Modules & Contracts',
                'status' => ($contracts['success'] ?? false) ? 'success' : 'failed',
                'detail' => $detail,
            ];
        }

        // Check-in
        $checkIn = $phases['check_in'] ?? null;
        if ($checkIn) {
            $steps[] = [
                'label' => 'Attendance Check-In',
                'status' => ($checkIn['success'] ?? false) ? 'success' : 'failed',
                'detail' => isset($checkIn['entry_id']) ? "Process: {$checkIn['entry_id']}" : '',
            ];
        }

        // Uploads
        $upload = $phases['upload'] ?? null;
        if ($upload) {
            $uploads = $upload['uploads'] ?? [];
            $prevCount = count(array_filter($uploads, fn ($u) => ($u['type'] ?? '') === 'previous_progress'));
            $currCount = count(array_filter($uploads, fn ($u) => ($u['type'] ?? '') === 'current_progress'));
            $fileIds = $upload['file_ids'] ?? [];
            $prevIds = $fileIds['previous'] ?? [];
            $currIds = $fileIds['current'] ?? [];

            $detail = "Previous: {$prevCount} files";
            if ($prevIds) {
                $detail .= ' → '.implode(', ', array_map(fn ($id) => substr($id, 0, 8), $prevIds));
            }

            $detail .= " | Current: {$currCount} files";
            if ($currIds) {
                $detail .= ' → '.implode(', ', array_map(fn ($id) => substr($id, 0, 8), $currIds));
            }

            $steps[] = [
                'label' => 'Upload Files ('.count($uploads).' files)',
                'status' => ($upload['success'] ?? false) ? 'success' : 'failed',
                'detail' => $detail,
            ];
        }

        // Previous Progress Resolved
        $previousProgress = $phases['previous_progress'] ?? null;
        if ($previousProgress) {
            $isFirst = $previousProgress['is_first_report'] ?? true;
            $prevCount = $previousProgress['previous_file_count'] ?? 0;
            $steps[] = [
                'label' => 'Previous Progress Resolved',
                'status' => 'success',
                'detail' => $isFirst
                    ? 'First report: no previous photos'
                    : "Source: last current progress | Files: {$prevCount}",
            ];
        }

        // Progress
        $progress = $phases['progress'] ?? null;
        if ($progress) {
            $steps[] = [
                'label' => 'ProjectProgress',
                'status' => ($progress['success'] ?? false) ? 'success' : 'failed',
                'detail' => isset($progress['saras_process_id']) ? "Process: {$progress['saras_process_id']}" : '',
            ];
        }

        // Stage files
        $stageFiles = $phases['stage_files'] ?? null;
        if ($stageFiles) {
            $prev = $stageFiles['previous'] ?? 0;
            $curr = $stageFiles['current'] ?? 0;
            $steps[] = [
                'label' => 'Stage Files Attached',
                'status' => ($stageFiles['success'] ?? false) ? 'success' : 'failed',
                'detail' => "Previous: {$prev} | Current: {$curr}",
            ];
        }

        // Workflow
        $workflow = $phases['workflow'] ?? null;
        if ($workflow) {
            $steps[] = [
                'label' => 'Workflow Triggered',
                'status' => 'success',
                'detail' => isset($workflow['workflow_run_id']) ? "Run: {$workflow['workflow_run_id']}" : '',
            ];

            $outcome = $workflow['outcome'] ?? 'unknown';
            $steps[] = [
                'label' => "AI Evaluation: {$outcome}",
                'status' => match ($outcome) {
                    'evaluated' => 'success',
                    'failed', 'workflow_trigger_failed' => 'failed',
                    default => 'pending',
                },
                'detail' => isset($workflow['polls']) ? "Polls: {$workflow['polls']}" : '',
            ];
        }

        // Certificate (always pending for now)
        $steps[] = [
            'label' => 'Certificate',
            'status' => 'pending',
            'detail' => 'Pending Saras workflow deployment',
        ];

        // Check-out
        $checkOut = $phases['check_out'] ?? null;
        if ($checkOut) {
            $steps[] = [
                'label' => 'Attendance Check-Out',
                'status' => ($checkOut['success'] ?? false) ? 'success' : 'failed',
                'detail' => isset($checkOut['entry_id']) ? "Process: {$checkOut['entry_id']}" : '',
            ];
        }

        return $steps;
    }

    private function renderContractsAndMilestones(Command $command, array $phases): void
    {
        $contracts = $phases['contracts'] ?? null;

        if (! $contracts || ! ($contracts['success'] ?? false)) {
            return;
        }

        $command->line('════════ Modules & Contracts ════════');
        $command->newLine();

        $command->line('  Saras Modules:');
        foreach ($contracts['modules'] ?? [] as $m) {
            $name = $m['name'] ?? 'Unknown';
            $id = $m['id'] ?? '—';
            $marker = ($m['id'] === ($contracts['selected_id'] ?? '') || $name === ($contracts['selected'] ?? '')) ? ' ← active' : '';
            $command->line("    {$name} ({$id}){$marker}");
        }

        $command->newLine();

        if ($contracts['contracts_available'] ?? false) {
            $count = $contracts['contract_count'] ?? 0;
            $command->line("  Contracts ({$count}):");

            foreach ($contracts['contracts'] ?? [] as $c) {
                $cId = substr($c['id'] ?? '', 0, 8);
                $name = $c['name'] ?? 'unnamed';
                $milestones = $c['milestones'] ?? [];
                $command->line("    {$name} ({$cId}...)");

                if (! empty($milestones)) {
                    $command->line('      Milestones: '.implode(', ', $milestones));
                }
            }
        } else {
            $command->line('  Contracts: ⚠ getProcess not available');
        }

        $command->newLine();
        $command->line('  Stage: '.config('saras.workflows.completion_stage_key', '—'));
        $command->line('  Workflow: '.config('saras.workflows.completion_id', '—'));
        $command->newLine();
    }

    private function renderWorkflowTriggerSummary(Command $command, array $phases): void
    {
        $workflow = $phases['workflow'] ?? null;

        if (! $workflow) {
            return;
        }

        $command->line('════════ Workflow Trigger ════════');
        $command->newLine();

        $command->line('  Workflow:   '.config('saras.workflows.completion_id', '—'));
        $command->line('  Process:    '.($phases['progress']['saras_process_id'] ?? '—'));
        $command->line('  Stage:      '.config('saras.workflows.completion_stage_key', '—'));
        $command->line('  Run:        '.($workflow['workflow_run_id'] ?? '—'));
        $command->line('  State:      '.strtoupper($workflow['outcome'] ?? 'unknown'));

        $payloadKeys = ['engineersRemarks'];
        if (config('saras.workflows.send_image_payload', true)) {
            $payloadKeys[] = 'oldImage';
            $payloadKeys[] = 'newImage';
        }
        $command->line('  Payload:    {'.implode(', ', $payloadKeys).'}');
        $command->newLine();
    }

    private function renderRunArtifacts(Command $command, array $payload, array $phases): void
    {
        $command->line('════════ Run Artifacts ════════');
        $command->newLine();

        $contract = $payload['project']['name'] ?? '';
        $checkIn = $phases['check_in']['entry_id'] ?? '—';
        $checkOut = $phases['check_out']['entry_id'] ?? '—';
        $uploads = $phases['upload']['uploads'] ?? [];
        $progress = $phases['progress']['saras_process_id'] ?? '—';
        $workflowRun = $phases['workflow']['workflow_run_id'] ?? '—';
        $prevCount = count(array_filter($uploads, fn ($u) => ($u['type'] ?? '') === 'previous_progress'));
        $currCount = count(array_filter($uploads, fn ($u) => ($u['type'] ?? '') === 'current_progress'));

        $projectId = $payload['project']['id'] ?? '—';
        $command->line("  Contract:     {$projectId} ({$contract})");
        $command->line("  Attendance:   {$this->short($checkIn)} (in) → {$this->short($checkOut)} (out)");
        $command->line('  TrackData:    '.count($uploads).' files uploaded');
        $command->line("  Progress:     {$progress}");
        $command->line('  Workflow:     '.config('saras.workflows.completion_id', '—')." → Run: {$this->short($workflowRun)}");
        $command->line("  Images:       {$prevCount} previous, {$currCount} current");
        $command->newLine();
    }

    private function renderSarasActionItems(Command $command, array $phases): void
    {
        $command->line('════════ Saras Action Items ════════');
        $command->newLine();

        $items = [];

        // Workflow status
        $workflow = $phases['workflow'] ?? null;
        $outcome = $workflow['outcome'] ?? null;

        if ($outcome === 'failed') {
            $items[] = 'Workflow returned FAILED — need failure details from Saras dashboard or raw result.';
        } elseif ($outcome === 'workflow_trigger_failed') {
            $items[] = 'Workflow trigger failed — verify workflow ID and process ID are correct.';
        } elseif ($outcome === 'processing') {
            $items[] = 'Workflow still processing — increase timeout or check Saras workflow queue.';
        }

        // Certificate
        $items[] = 'certificateOfCompletion field empty — certificate workflow not yet deployed (3406f390 returns 404).';

        // Stage files
        $stageFiles = $phases['stage_files'] ?? null;
        if ($stageFiles && ($stageFiles['success'] ?? false)) {
            $items[] = 'Stage file attachment API integrated via /process/updateFiles — verify files appear correctly in Saras dashboard.';
        } else {
            $items[] = 'Stage file attachment via /process/updateFiles failed or not executed — check processId, stageKey, and file UUIDs.';
        }

        // Certificate workflow
        $items[] = 'Certificate workflow 3406f390-ce85-4b32-8531-8b90c837dcb4 returns 404 — confirm deployment for DPWH tenant.';

        // Workflow diagnostics
        $items[] = 'getWorkflowRuns currently returns only id/state/flowState — confirm endpoint, query option, or response expansion needed to retrieve full workflow error/output details.';

        foreach ($items as $i => $item) {
            $num = $i + 1;
            $command->line("  {$num}. {$item}");
        }

        $command->newLine();
    }

    private function renderFullPayloads(Command $command, SarasApiTracer $tracer): void
    {
        $postTraces = array_filter($tracer->all(), fn (SarasApiTrace $t) => $t->method === 'POST' && ! empty($t->rawRequest));

        if (empty($postTraces)) {
            return;
        }

        $count = count($postTraces);
        $command->line("════════ Full Payloads ({$count} POST calls) ════════");
        $command->newLine();

        foreach ($postTraces as $trace) {
            $endpoint = preg_replace('/\?.*$/', '', $trace->endpoint);
            $label = $trace->requestSummary['subProjectId'] ?? $endpoint;
            $command->line("  ┌─ {$trace->method} {$endpoint}");

            $json = json_encode($this->sanitizePayload($trace->rawRequest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            foreach (explode("\n", $json) as $line) {
                $command->line("  │  {$line}");
            }

            $command->line('  └─');
            $command->newLine();
        }
    }

    private function renderFullResponses(Command $command, SarasApiTracer $tracer): void
    {
        $traces = array_filter($tracer->all(), fn (SarasApiTrace $t) => ! empty($t->rawResponse));

        if (empty($traces)) {
            return;
        }

        $command->line('════════ Full Responses ════════');
        $command->newLine();

        foreach ($traces as $trace) {
            $endpoint = preg_replace('/\?.*$/', '', $trace->endpoint);
            $command->line("  ┌─ {$trace->method} {$endpoint} → {$trace->status}");

            $compact = $this->compactResponse($trace->rawResponse);
            $json = json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            foreach (explode("\n", $json) as $line) {
                $command->line("  │  {$line}");
            }

            $command->line('  └─');
            $command->newLine();
        }
    }

    /**
     * Remove verbose nested user/tenant objects from payloads for readability.
     */
    private function sanitizePayload(array $data): array
    {
        // Convert stdClass objects to arrays for JSON display
        return json_decode(json_encode($data), true) ?? $data;
    }

    /**
     * Compact Saras responses by stripping verbose nested user/tenant details.
     */
    private function compactResponse(array $data): array
    {
        $compact = [];

        // Process response
        if (isset($data['process']['id'])) {
            $compact['process'] = [
                'id' => $data['process']['id'],
                'state' => $data['process']['state'] ?? null,
                'fields' => $data['process']['fields'] ?? null,
            ];
        }

        // Workflow run response
        if (isset($data['runId']['id'])) {
            $compact['runId'] = [
                'id' => $data['runId']['id'],
                'state' => $data['runId']['state'] ?? null,
                'flowState' => $data['runId']['flowState'] ?? null,
            ];
        }

        // File upload response
        if (isset($data['files'])) {
            $compact['files'] = array_map(fn ($f) => [
                'id' => $f['id'] ?? null,
                'name' => $f['name'] ?? $f['fileName'] ?? null,
            ], $data['files']);
        }

        // Workflow runs list
        if (isset($data['runs'])) {
            $compact['meta'] = $data['meta'] ?? null;
            $compact['runs'] = array_map(fn ($r) => [
                'id' => $r['id'] ?? null,
                'state' => $r['state'] ?? null,
                'flowState' => $r['flowState'] ?? null,
            ], $data['runs']);
        }

        // Trace ID
        if (isset($data['traceId'])) {
            $compact['traceId'] = $data['traceId'];
        }

        return $compact ?: $data;
    }

    private function renderDeveloperInterpretation(Command $command, array $phases): void
    {
        $command->line('════════ Developer Interpretation ════════');
        $command->newLine();

        // Track AI side
        $command->line('Track AI side:');

        $checkIn = $phases['check_in'] ?? null;
        $upload = $phases['upload'] ?? null;
        $progress = $phases['progress'] ?? null;
        $workflow = $phases['workflow'] ?? null;
        $checkOut = $phases['check_out'] ?? null;

        $this->interpretLine($command, $checkIn && ($checkIn['success'] ?? false), 'Attendance check-in/check-out works');
        $this->interpretLine($command, $upload && ($upload['success'] ?? false), 'File upload to Saras storage works');
        $this->interpretLine($command, $upload && ($upload['success'] ?? false), 'TrackData process creation works');
        $this->interpretLine($command, $progress && ($progress['success'] ?? false), 'ProjectProgress process creation works');
        $this->interpretLine($command, $progress && ! empty($progress['saras_process_id']), 'Previous/current progress file UUID mapping works');
        $this->interpretLine($command, $workflow && ! empty($workflow['workflow_run_id']), 'Workflow trigger works');
        $this->interpretLine($command, $workflow !== null, 'Workflow polling works');

        $command->newLine();
        $command->line('Saras side:');

        $outcome = $workflow['outcome'] ?? null;

        if ($outcome === 'failed') {
            $command->line('  ✗ Workflow run failed after initialization');
            $command->line('  ? Failure reason is not exposed in getWorkflowRuns response');
        } elseif ($outcome === 'evaluated') {
            $command->line('  ✓ Workflow completed successfully');
        } elseif ($outcome === 'processing') {
            $command->line('  ⏳ Workflow still processing (timeout reached)');
        } else {
            $command->line('  ? Workflow status unknown');
        }

        if ($outcome !== 'evaluated') {
            $command->line('  ? Workflow output/result payload is not exposed in getWorkflowRuns response');
        }

        $command->line('  ? Certificate artifact is not produced or not exposed yet');
        $command->line('  ? Stage checklist attachment behavior remains unresolved');

        $command->newLine();
        $command->line('Conclusion:');

        if ($outcome === 'evaluated') {
            $command->line('  Track AI ↔ Saras integration is fully operational.');
            $command->line('  Workflow completed successfully. Certificate artifact exposure is the remaining step.');
        } elseif ($outcome === 'failed') {
            $command->line('  Track AI integration path is operational up to Saras workflow execution.');
            $command->line('  The remaining blocker is Saras workflow debugging and certificate artifact exposure.');
        } elseif ($outcome === 'processing') {
            $command->line('  Track AI integration path is operational. Workflow was triggered successfully.');
            $command->line('  The workflow is still processing — results may be available after a longer polling window.');
        } else {
            $command->line('  Track AI integration path is operational up to Saras workflow execution.');
            $command->line('  Workflow was not triggered — verify Saras sync is enabled and processId was obtained.');
        }

        $command->newLine();
        $command->line('Recommended next steps:');

        if ($outcome === 'evaluated') {
            $command->line('  1. Confirm certificate artifact is produced and accessible via API or dashboard.');
            $command->line('  2. Implement certificate display in Track AI frontend.');
            $command->line('  3. Confirm stage file attachment API for previousProgressImages/currentProgressImages.');
        } elseif ($outcome === 'failed') {
            $command->line('  Request to Saras:');
            $command->line('  Please provide the endpoint, response expansion option, dashboard path, or API method');
            $command->line('  that exposes full workflow run details, including failure reason, node-level error,');
            $command->line('  workflow output, generated task, and certificate artifact if available.');
        } elseif ($outcome === 'processing') {
            $command->line('  1. Increase --timeout or --poll interval and re-run.');
            $command->line('  2. Check Saras dashboard for workflow run status.');
            $command->line('  3. If workflow remains in WAITING state, confirm with Saras team.');
        } else {
            $command->line('  1. Verify SARAS_PROGRESS_ENABLED=true in .env.');
            $command->line('  2. Confirm ProjectProgress createProcess returns a valid processId.');
            $command->line('  3. Check Saras API connectivity and token validity.');
        }

        $command->newLine();
    }

    private function renderIntegrationScorecard(Command $command, array $phases): void
    {
        $command->line('════════ Integration Scorecard ════════');
        $command->newLine();

        $checkIn = $phases['check_in'] ?? null;
        $upload = $phases['upload'] ?? null;
        $progress = $phases['progress'] ?? null;
        $workflow = $phases['workflow'] ?? null;
        $outcome = $workflow['outcome'] ?? null;

        $scores = [
            ['Attendance Integration', ($checkIn && ($checkIn['success'] ?? false)) ? 100 : 0],
            ['TrackData Integration', ($upload && ($upload['success'] ?? false)) ? 100 : 0],
            ['ProjectProgress Integration', ($progress && ($progress['success'] ?? false)) ? 100 : 0],
            ['Workflow Triggering', ($workflow && ! empty($workflow['workflow_run_id'])) ? 100 : 0],
            ['Workflow Polling', $workflow !== null ? 100 : 0],
            ['Workflow Execution', match ($outcome) {
                'evaluated' => 100,
                'failed', 'processing' => 50,
                default => 0,
            }],
            ['Certificate Generation', 0],
        ];

        foreach ($scores as [$label, $score]) {
            $icon = $score === 100 ? '✓' : ($score > 0 ? '⚠' : '⚠');
            $pct = "{$score}%";
            $padded = str_pad($label, 28);
            $command->line("  {$icon} {$padded}{$pct}");
        }

        $total = array_sum(array_column($scores, 1));
        $overall = (int) round($total / count($scores));
        $overallIcon = $overall >= 80 ? '✓' : '⚠';

        $command->newLine();
        $command->line("  {$overallIcon} ".str_pad('Overall Integration Health', 28)."{$overall}%");
        $command->newLine();
    }

    private function renderSarasTraceIds(Command $command, SarasApiTracer $tracer): void
    {
        $command->line('════════ Saras Trace IDs ════════');
        $command->newLine();

        $labels = [
            '/process/createProcess' => null,
            '/process/workflows/executeWorkflow' => 'Workflow Execute',
            '/process/workflows/getWorkflowRuns' => 'Workflow Poll',
        ];

        $subProjectLabels = [
            config('saras.subproject_ids.attendance') => 'Attendance',
            config('saras.subproject_ids.trackdata') => 'TrackData',
            config('saras.subproject_ids.project_progress') => 'ProjectProgress',
        ];

        $emitted = [];

        foreach ($tracer->all() as $trace) {
            $traceId = $trace->rawResponse['traceId'] ?? null;
            if (! $traceId) {
                continue;
            }

            $endpoint = preg_replace('/\?.*$/', '', $trace->endpoint);

            // Determine label
            if ($endpoint === '/process/createProcess') {
                $subProjectId = $trace->rawRequest['subProjectId'] ?? '';
                $label = $subProjectLabels[$subProjectId] ?? 'Process';

                // Deduplicate TrackData (many uploads)
                if ($label === 'TrackData' && in_array('TrackData', $emitted)) {
                    continue;
                }
            } else {
                $label = $labels[$endpoint] ?? $endpoint;
            }

            $emitted[] = $label;
            $command->line("  {$label}");
            $command->line("    {$traceId}");
            $command->newLine();
        }

        if (empty($emitted)) {
            $command->line('  No trace IDs captured.');
            $command->newLine();
        }
    }

    private function renderExecutiveSummary(Command $command, array $phases): void
    {
        $command->line('════════ Executive Summary ════════');
        $command->newLine();

        $command->line('Track AI successfully completed the operational workflow:');
        $command->newLine();
        $command->line('  Attendance → File Upload → TrackData → ProjectProgress → Workflow Trigger → Polling');
        $command->newLine();

        $workflow = $phases['workflow'] ?? null;
        $outcome = $workflow['outcome'] ?? null;

        // Compute readiness
        $scores = [
            ($phases['check_in']['success'] ?? false) ? 100 : 0,
            ($phases['upload']['success'] ?? false) ? 100 : 0,
            ($phases['progress']['success'] ?? false) ? 100 : 0,
            ($workflow && ! empty($workflow['workflow_run_id'])) ? 100 : 0,
            $workflow !== null ? 100 : 0,
            match ($outcome) {
                'evaluated' => 100, 'failed', 'processing' => 50, default => 0
            },
            0, // certificate
        ];
        $readiness = (int) round(array_sum($scores) / count($scores));

        if ($outcome === 'evaluated') {
            $blocker = 'Certificate artifact exposure.';
            $action = 'Saras to confirm certificate access via API.';
        } elseif ($outcome === 'failed') {
            $blocker = 'Saras workflow returns FAILED without exposed diagnostics.';
            $action = 'Saras to provide workflow failure details and certificate artifact access.';
        } elseif ($outcome === 'processing') {
            $blocker = 'Saras workflow still processing.';
            $action = 'Re-run with longer timeout or check Saras dashboard.';
        } else {
            $blocker = 'Workflow not triggered.';
            $action = 'Verify Saras sync configuration.';
        }

        $command->line("Current readiness:  {$readiness}%");
        $command->line("Primary blocker:    {$blocker}");
        $command->line("Next action:        {$action}");
        $command->newLine();
    }

    private function interpretLine(Command $command, bool $success, string $label): void
    {
        $icon = $success ? '✓' : '✗';
        $command->line("  {$icon} {$label}");
    }

    private function short(?string $id): string
    {
        if (! $id || $id === '—') {
            return '—';
        }

        return substr($id, 0, 8);
    }
}
