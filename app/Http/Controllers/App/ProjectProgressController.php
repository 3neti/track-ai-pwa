<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ProjectProgressRequest;
use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectProgressController extends Controller
{
    public function __construct(
        protected ProjectProgressService $progressService,
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * Display the project progress page.
     */
    public function index(): Response
    {
        $projects = Project::orderBy('name')->get();

        $contracts = [];

        try {
            $contractAiId = config('saras.subproject_ids.contract_ai');
            $response = $this->sarasClient->getProcesses($contractAiId, 1, 50);

            foreach ($response['processes'] ?? [] as $c) {
                $contracts[] = [
                    'id' => $c['id'] ?? '',
                    'name' => $c['fields']['legalName1'] ?? $c['metaDetails']['title'] ?? 'Contract #'.($c['metaDetails']['displayNumber'] ?? '?'),
                    'milestones' => $c['fields']['milestone'] ?? [],
                    'display_number' => $c['metaDetails']['displayNumber'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            // Contracts not available — page still renders
        }

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
        // Check local reports
        $localReports = ProjectProgressReport::where('contract_id', $contractId)
            ->whereNotIn('progress_status', ['draft', 'failed'])
            ->whereNotNull('current_milestone')
            ->get(['current_milestone', 'progress_status', 'certificate_file_id']);

        $milestoneStatus = [];
        foreach ($localReports as $report) {
            $milestoneStatus[$report->current_milestone] = [
                'has_progress' => true,
                'has_certificate' => ! empty($report->certificate_file_id),
                'status' => $report->progress_status,
            ];
        }

        // Also check Saras ProjectProgress records (covers reports not in local DB)
        try {
            $ppSubId = config('saras.subproject_ids.project_progress');
            $ppResponse = $this->sarasClient->getProcesses($ppSubId, 1, 50);

            foreach ($ppResponse['processes'] ?? [] as $pp) {
                $ppContractId = $pp['fields']['contractId'] ?? null;
                $ppMilestone = $pp['fields']['currentMilestone'] ?? null;

                if ($ppContractId !== $contractId || ! $ppMilestone) {
                    continue;
                }

                // Don't overwrite local data if already present
                if (! isset($milestoneStatus[$ppMilestone])) {
                    $milestoneStatus[$ppMilestone] = [
                        'has_progress' => true,
                        'has_certificate' => ! empty($pp['fields']['certificateOfCompletion'] ?? null),
                        'status' => 'submitted',
                    ];
                }
            }
        } catch (\Exception $e) {
            // Saras unavailable — proceed with local data only
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
        $milestone = urldecode($milestone);
        $fileIds = $this->progressService->resolvePreviousProgressFileIds($contractId, $milestone);

        return response()->json([
            'success' => true,
            'isFirstReport' => empty($fileIds),
            'previousFileCount' => count($fileIds),
            'previousFileIds' => $fileIds,
        ]);
    }
}
