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
            $contractAiId = 'acfdb45a-f4fd-4e25-8e52-de8ae6ff5b99';
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
     * List available contracts with milestones.
     */
    public function contracts(): JsonResponse
    {
        try {
            $contractAiId = 'acfdb45a-f4fd-4e25-8e52-de8ae6ff5b99';
            $response = $this->sarasClient->getProcesses($contractAiId, 1, 50);

            $contracts = [];

            foreach ($response['processes'] ?? [] as $c) {
                $contracts[] = [
                    'id' => $c['id'] ?? '',
                    'name' => $c['fields']['legalName1'] ?? $c['metaDetails']['title'] ?? 'Contract #'.($c['metaDetails']['displayNumber'] ?? '?'),
                    'milestones' => $c['fields']['milestone'] ?? [],
                    'display_number' => $c['metaDetails']['displayNumber'] ?? '',
                ];
            }

            return response()->json(['success' => true, 'contracts' => $contracts]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'contracts' => [], 'message' => $e->getMessage()]);
        }
    }
}
