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
        $this->renderRunArtifacts($command, $payload, $phases);
        $this->renderSarasActionItems($command, $phases);
        $this->renderFullPayloads($command, $tracer);
        $this->renderFullResponses($command, $tracer);
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

        // Progress
        $progress = $phases['progress'] ?? null;
        if ($progress) {
            $steps[] = [
                'label' => 'ProjectProgress',
                'status' => ($progress['success'] ?? false) ? 'success' : 'failed',
                'detail' => isset($progress['saras_process_id']) ? "Process: {$progress['saras_process_id']}" : '',
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
        $items[] = 'Stage checklist (previousProgressImages/currentProgressImages) shows empty on dashboard — confirm stage file attachment API.';

        // Certificate workflow
        $items[] = 'Certificate workflow 3406f390-ce85-4b32-8531-8b90c837dcb4 returns 404 — confirm deployment for DPWH tenant.';

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

    private function short(?string $id): string
    {
        if (! $id || $id === '—') {
            return '—';
        }

        return substr($id, 0, 8);
    }
}
