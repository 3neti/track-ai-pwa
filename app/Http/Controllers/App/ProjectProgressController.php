<?php

namespace App\Http\Controllers\App;

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
    ) {}

    /**
     * Display the project progress page.
     */
    public function index(): Response
    {
        $projects = Project::orderBy('name')->get();

        return Inertia::render('app/ProjectProgress', [
            'projects' => $projects,
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
}
