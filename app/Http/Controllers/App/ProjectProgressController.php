<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ProjectProgressRequest;
use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Services\TrackAI\ContractService;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ProjectProgressController extends Controller
{
    public function __construct(
        protected ProjectProgressService $progressService,
        protected ContractService $contractService,
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * Display the project progress page.
     */
    public function index(Request $request): Response
    {
        $projects = Project::orderBy('name')->get();
        $defaultProject = $this->resolveDefaultProject();

        $contracts = $this->contractService->listContracts(refresh: true)
            ->map(fn ($contract) => [
                'id' => $contract->saras_process_id,
                'local_id' => $contract->id,
                'saras_process_id' => $contract->saras_process_id,
                'name' => $contract->name,
                'milestones' => $contract->milestones ?? [],
                'display_number' => $contract->display_number ?? '',
            ]);

        $this->syncProjectProgressCache($request, $defaultProject);

        return Inertia::render('app/ProjectProgress', [
            'projects' => $projects,
            'contracts' => $contracts,
            'defaultProjectId' => config('saras.project_id'),
        ]);
    }

    /**
     * List progress reports for a project.
     */
    public function list(Project $project): JsonResponse
    {
        $reports = $this->progressService->getProgressForProject($project);

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Create a new progress report.
     */
    public function store(ProjectProgressRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $contractId = $validated['contract_id'] ?? $project->contract_id ?: config('saras.default_contract_id');
        $milestone = $validated['current_milestone'] ?? null;

        $blocker = $contractId && $milestone
            ? $this->progressService->progressSubmissionBlocker($contractId, $milestone)
            : null;

        if ($blocker) {
            return response()->json([
                'success' => false,
                'message' => $blocker,
            ], 423);
        }

        $report = $this->progressService->createProgress(
            user: $user,
            project: $project,
            input: array_merge($validated, [
                'ip_address' => $request->ip(),
            ]),
        );

        return response()->json([
            'success' => true,
            'report' => $report,
            'message' => $report->saras_process_id
                ? 'Progress report submitted to Saras.'
                : 'Progress report saved locally.',
        ]);
    }

    /**
     * Trigger the completion workflow.
     */
    public function triggerWorkflow(ProjectProgressReport $progressReport): JsonResponse
    {
        $report = $this->progressService->triggerWorkflow($progressReport);

        return response()->json([
            'success' => $report->isProcessing(),
            'report' => $report,
            'message' => $report->isProcessing()
                ? 'AI evaluation started.'
                : 'Failed to start workflow.',
        ]);
    }

    /**
     * Poll workflow status.
     */
    public function workflowStatus(ProjectProgressReport $progressReport): JsonResponse
    {
        $run = $this->progressService->pollWorkflowStatus($progressReport);

        return response()->json([
            'success' => true,
            'report' => $progressReport->fresh(),
            'workflow_run' => $run ? [
                'id' => $run->id,
                'state' => $run->state,
                'flow_state' => $run->flowState,
                'updated_at' => $run->updatedTs,
            ] : null,
        ]);
    }

    /**
     * Attach stage files to a progress report.
     */
    public function attachStageFiles(ProjectProgressReport $progressReport): JsonResponse
    {
        $result = $this->progressService->attachStageFiles($progressReport);

        return response()->json($result);
    }

    /**
     * Get milestone progress status for a contract.
     *
     * Checks both local reports and Saras ProjectProgress records.
     */
    public function milestoneProgress(string $contractId): JsonResponse
    {
        $this->syncProjectProgressCache(request(), $this->resolveDefaultProject());

        // Check local reports
        $localReports = ProjectProgressReport::where('contract_id', $contractId)
            ->whereNotIn('progress_status', ['draft', 'failed'])
            ->whereNotNull('current_milestone')
            ->whereNull('remote_deleted_at')
            ->get(['current_milestone', 'progress_status', 'certificate_file_id']);

        $milestoneStatus = [];
        foreach ($localReports as $report) {
            $milestoneStatus[$report->current_milestone] = [
                'has_progress' => true,
                'has_certificate' => ! empty($report->certificate_file_id),
                'status' => $report->progress_status,
            ];
        }

        return response()->json([
            'success' => true,
            'milestones' => $milestoneStatus,
        ]);
    }

    /**
     * Get previous progress photo status for a contract/milestone.
     */
    public function previousProgress(string $contractId, string $milestone): JsonResponse
    {
        $this->syncProjectProgressCache(request(), $this->resolveDefaultProject());

        $milestone = urldecode($milestone);
        $fileIds = $this->progressService->resolvePreviousProgressFileIds($contractId, $milestone);

        return response()->json([
            'success' => true,
            'isFirstReport' => empty($fileIds),
            'previousFileCount' => count($fileIds),
            'previousFileIds' => $fileIds,
        ]);
    }

    protected function resolveDefaultProject(): ?Project
    {
        return Project::where('external_id', config('saras.project_id'))->first()
            ?? Project::query()->first();
    }

    protected function syncProjectProgressCache(Request $request, ?Project $project): void
    {
        $user = $request->user();

        if (! $user || ! $project) {
            return;
        }

        try {
            $this->progressService->syncProjectProgressFromSaras($user, $project);
        } catch (\Throwable $e) {
            Log::warning('ProjectProgress: Saras progress cache sync failed', [
                'user_id' => $user->id,
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
