<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\ProgressSubmitRequest;
use App\Services\TrackAI\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProgressController extends Controller
{
    public function __construct(
        protected ProgressService $progressService,
    ) {}

    /**
     * Display the progress page.
     */
    public function index(): Response
    {
        $projects = \App\Models\Project::orderBy('name')->get();

        return Inertia::render('app/Progress', [
            'projects' => $projects,
        ]);
    }

    /**
     * Submit a progress update.
     */
    public function submit(ProgressSubmitRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $response = $this->progressService->submitProgress(
            userId: $user->id,
            contractId: $validated['contract_id'],
            checklistItems: $validated['checklist_items'],
            remarks: $validated['remarks'] ?? null,
            latitude: $validated['latitude'] ?? 0,
            longitude: $validated['longitude'] ?? 0,
            ipAddress: $request->ip(),
        );

        return response()->json([
            'success' => $response->success,
            'entry_id' => $response->entryId,
            'message' => $response->message,
        ]);
    }

    /**
     * Upload a progress photo.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => ['required', 'string'],
            'entry_id' => ['required', 'string'],
            'photo_type' => ['required', 'string', 'in:top_view,left_side,right_side,front_view,back_view,detail'],
            'file' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $user = $request->user();

        $response = $this->progressService->uploadProgressPhoto(
            userId: $user->id,
            contractId: $request->input('contract_id'),
            entryId: $request->input('entry_id'),
            file: $request->file('file'),
            photoType: $request->input('photo_type'),
        );

        return response()->json([
            'success' => $response->success,
            'file_id' => $response->fileId,
            'file_url' => $response->fileUrl,
            'message' => $response->message,
        ]);
    }

    /**
     * Run AI analysis on a progress entry.
     */
    public function runAi(Request $request): JsonResponse
    {
        $request->validate([
            'contract_id' => ['required', 'string'],
            'entry_id' => ['required', 'string'],
        ]);

        $user = $request->user();

        $response = $this->progressService->runAiAnalysis(
            userId: $user->id,
            contractId: $request->input('contract_id'),
            entryId: $request->input('entry_id'),
        );

        return response()->json([
            'success' => $response->success,
            'workflow_id' => $response->workflowId,
            'status' => $response->status,
            'message' => $response->message,
        ]);
    }

    /**
     * Get AI workflow status.
     */
    public function aiStatus(string $workflowId): JsonResponse
    {
        $response = $this->progressService->getAiStatus($workflowId);

        return response()->json([
            'success' => $response->success,
            'workflow_id' => $response->workflowId,
            'status' => $response->status,
            'results' => $response->results,
            'message' => $response->message,
        ]);
    }
}
