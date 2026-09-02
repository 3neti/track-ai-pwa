<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreUploadRequest;
use App\Http\Requests\App\UpdateUploadRequest;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Upload;
use App\Services\Saras\SarasProjectContextResolver;
use App\Services\TrackAI\ProjectProgressService;
use App\Services\TrackAI\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectUploadController extends Controller
{
    public function __construct(
        protected UploadService $uploadService,
        protected SarasClientInterface $sarasClient,
        protected ProjectProgressService $progressService,
        protected SarasProjectContextResolver $contextResolver,
    ) {}

    /**
     * Display the project uploads page.
     */
    public function page(): Response
    {
        $projects = Project::orderBy('name')->get();

        $contracts = [];

        try {
            $response = $this->sarasClient->getProcesses(
                $this->contextResolver->subProjectId('contract_ai'), 1, 50
            );

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

        return Inertia::render('app/Project/Uploads', [
            'projects' => $projects,
            'contracts' => $contracts,
            'defaultProjectId' => config('saras.project_id'),
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

        // Filter by contract
        if ($request->filled('contract_id')) {
            $query->forContract($request->input('contract_id'));
        }

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
        $milestone = $this->currentProgressMilestone($validated);
        $title = $milestone
            ? $this->currentProgressTitle($validated['contract_id'], $milestone)
            : $validated['title'];

        $blocker = $milestone
            ? $this->progressService->uploadSubmissionBlocker($validated['contract_id'], $milestone)
            : null;

        if ($blocker) {
            return response()->json([
                'success' => false,
                'message' => $blocker,
            ], 423);
        }

        $upload = $this->uploadService->createUploadRecord(
            userId: $user->id,
            contractId: $validated['contract_id'],
            title: $title,
            documentType: $validated['document_type'],
            clientRequestId: $validated['client_request_id'],
            tags: $validated['tags'] ?? null,
            remarks: $validated['remarks'] ?? null,
            mime: $validated['mime'] ?? null,
            size: $validated['size'] ?? null,
            projectId: $project->id,
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
        $this->ensureUploadBelongsToProject($upload, $project);
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
        $this->ensureUploadBelongsToProject($upload, $project);
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
        $this->ensureUploadBelongsToProject($upload, $project);
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
        $this->ensureUploadBelongsToProject($upload, $project);
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

    /**
     * Upload a file for an existing upload record.
     */
    public function file(Request $request, Project $project, Upload $upload): JsonResponse
    {
        $this->ensureUploadBelongsToProject($upload, $project);
        $this->authorize('update', $upload);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20MB max
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Check upload is in a state that allows file upload
        if ($upload->status === Upload::STATUS_UPLOADED) {
            return response()->json([
                'success' => false,
                'message' => 'File already uploaded for this record.',
            ], 422);
        }

        if ($upload->isLocked()) {
            return response()->json([
                'success' => false,
                'message' => "Upload is locked: {$upload->locked_reason}",
            ], 423);
        }

        $upload = $this->uploadService->uploadFileToRemote(
            upload: $upload,
            file: $request->file('file'),
            latitude: $request->input('latitude', 0),
            longitude: $request->input('longitude', 0),
            ipAddress: $request->ip(),
        );

        if ($upload->isFailed()) {
            return response()->json([
                'success' => false,
                'upload' => $upload,
                'message' => $upload->last_error ?? 'File upload failed.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'upload' => $upload,
            'message' => 'File uploaded successfully.',
        ]);
    }

    protected function ensureUploadBelongsToProject(Upload $upload, Project $project): void
    {
        abort_unless($upload->project_id === $project->id, 404);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function currentProgressMilestone(array $validated): ?string
    {
        if (($validated['document_type'] ?? null) !== 'current_progress') {
            return null;
        }

        $tags = array_values(array_filter($validated['tags'] ?? []));

        foreach ($tags as $tag) {
            if (! in_array($tag, ['progress', 'current_progress'], true)) {
                return (string) $tag;
            }
        }

        return null;
    }

    protected function currentProgressTitle(string $contractId, string $milestone): string
    {
        $contractName = Contract::where('saras_process_id', $contractId)->value('name')
            ?: $contractId
            ?: 'Contract';

        return "{$contractName}-{$milestone}";
    }
}
