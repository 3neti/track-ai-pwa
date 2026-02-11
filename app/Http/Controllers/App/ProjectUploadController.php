<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreUploadRequest;
use App\Http\Requests\App\UpdateUploadRequest;
use App\Models\Project;
use App\Models\Upload;
use App\Services\TrackAI\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectUploadController extends Controller
{
    public function __construct(
        protected UploadService $uploadService,
    ) {}

    /**
     * Display the project uploads page.
     */
    public function page(): Response
    {
        $projects = Project::orderBy('name')->get();

        return Inertia::render('app/Project/Uploads', [
            'projects' => $projects,
        ]);
    }

    /**
     * List uploads for a project.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $query = Upload::forProject($project->id)
            ->with('user:id,name')
            ->latest();

        // Filter by status
        if ($request->filled('status')) {
            $query->byStatus($request->input('status'));
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $query->withTag($request->input('tag'));
        }

        // Search by title/remarks
        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        $uploads = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $uploads->items(),
            'meta' => [
                'current_page' => $uploads->currentPage(),
                'last_page' => $uploads->lastPage(),
                'per_page' => $uploads->perPage(),
                'total' => $uploads->total(),
            ],
        ]);
    }

    /**
     * Create/enqueue a new upload.
     */
    public function store(StoreUploadRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $upload = $this->uploadService->createUploadRecord(
            userId: $user->id,
            contractId: $validated['contract_id'],
            title: $validated['title'],
            documentType: $validated['document_type'],
            clientRequestId: $validated['client_request_id'],
            tags: $validated['tags'] ?? null,
            remarks: $validated['remarks'] ?? null,
            mime: $validated['mime'] ?? null,
            size: $validated['size'] ?? null,
        );

        return response()->json([
            'success' => true,
            'upload' => $upload,
            'message' => 'Upload enqueued successfully.',
        ], 201);
    }

    /**
     * Show a single upload.
     */
    public function show(Project $project, Upload $upload): JsonResponse
    {
        $this->authorize('view', $upload);

        return response()->json([
            'success' => true,
            'upload' => $upload->load('user:id,name', 'project:id,name,external_id,status'),
        ]);
    }

    /**
     * Update upload metadata.
     */
    public function update(UpdateUploadRequest $request, Project $project, Upload $upload): JsonResponse
    {
        $this->authorize('update', $upload);

        if (! $upload->isEditable()) {
            return response()->json([
                'success' => false,
                'message' => $upload->isLocked()
                    ? "Upload is locked: {$upload->locked_reason}"
                    : 'Upload cannot be edited.',
            ], 423);
        }

        $validated = $request->validated();

        $upload = $this->uploadService->updateMetadata(
            upload: $upload,
            userId: $request->user()->id,
            data: $validated,
        );

        return response()->json([
            'success' => true,
            'upload' => $upload,
            'message' => 'Upload updated successfully.',
        ]);
    }

    /**
     * Delete an upload.
     */
    public function destroy(Request $request, Project $project, Upload $upload): JsonResponse
    {
        $this->authorize('delete', $upload);

        if (! $upload->isDeletable()) {
            return response()->json([
                'success' => false,
                'message' => $upload->isLocked()
                    ? "Upload is locked: {$upload->locked_reason}"
                    : 'Upload cannot be deleted.',
            ], 423);
        }

        $reason = $request->input('reason');

        $this->uploadService->deleteUpload(
            upload: $upload,
            userId: $request->user()->id,
            reason: $reason,
        );

        return response()->json([
            'success' => true,
            'message' => 'Upload deleted successfully.',
        ]);
    }

    /**
     * Retry a failed upload.
     */
    public function retry(Request $request, Project $project, Upload $upload): JsonResponse
    {
        $this->authorize('retry', $upload);

        if (! $upload->isRetryable()) {
            return response()->json([
                'success' => false,
                'message' => 'Upload cannot be retried. It must be in failed status and not locked.',
            ], 422);
        }

        $upload = $this->uploadService->retryUpload(
            upload: $upload,
            userId: $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'upload' => $upload,
            'message' => 'Upload queued for retry.',
        ]);
    }
}
