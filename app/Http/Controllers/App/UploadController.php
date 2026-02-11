<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\UploadFileRequest;
use App\Http\Requests\App\UploadInitRequest;
use App\Models\Project;
use App\Models\Upload;
use App\Services\TrackAI\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Legacy upload controller.
 *
 * @deprecated Use ProjectUploadController instead. These endpoints create Upload
 *             records for backwards compatibility but new code should use the
 *             project-scoped endpoints.
 */
class UploadController extends Controller
{
    public function __construct(
        protected UploadService $uploadService,
    ) {}

    /**
     * Display the uploads page.
     */
    public function index(): Response
    {
        $projects = Project::orderBy('name')->get();

        return Inertia::render('app/Uploads', [
            'projects' => $projects,
        ]);
    }

    /**
     * Initialize an upload entry.
     *
     * Creates an Upload record and initializes remote entry in Saras.
     * Returns both upload_id (for new flow) and entry_id (for legacy).
     */
    public function init(UploadInitRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Generate client_request_id if not provided (needed for Upload record)
        $clientRequestId = $validated['client_request_id'] ?? Str::uuid()->toString();

        // Create Upload record first
        $upload = $this->uploadService->createUploadRecord(
            userId: $user->id,
            contractId: $validated['contract_id'],
            title: $validated['name'] ?? 'Untitled Upload',
            documentType: $validated['document_type'],
            clientRequestId: $clientRequestId,
            tags: $validated['tags'] ?? [],
            remarks: $validated['remarks'] ?? null,
        );

        // Initialize remote entry in Saras
        $upload = $this->uploadService->initRemoteEntry(
            upload: $upload,
            latitude: $validated['latitude'] ?? 0,
            longitude: $validated['longitude'] ?? 0,
            ipAddress: $request->ip(),
        );

        // Check if entry creation failed
        if ($upload->isFailed()) {
            return response()->json([
                'success' => false,
                'upload_id' => $upload->id,
                'entry_id' => null,
                'message' => $upload->last_error ?? 'Failed to create entry.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'upload_id' => $upload->id,
            'entry_id' => $upload->entry_id,
            'message' => 'Upload initialized successfully.',
        ]);
    }

    /**
     * Upload a file.
     *
     * Accepts upload_id (preferred) or entry_id (legacy fallback).
     */
    public function file(UploadFileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Find Upload record by upload_id or entry_id
        $upload = null;
        if (! empty($validated['upload_id'])) {
            $upload = Upload::find($validated['upload_id']);
        }
        if (! $upload && ! empty($validated['entry_id'])) {
            $upload = Upload::where('entry_id', $validated['entry_id'])->first();
        }

        // If no Upload record found, create one on-the-fly (edge case: old client)
        if (! $upload) {
            $upload = $this->uploadService->createUploadRecord(
                userId: $user->id,
                contractId: $validated['contract_id'],
                title: 'Legacy Upload',
                documentType: 'other',
                clientRequestId: Str::uuid()->toString(),
            );
            $upload->update(['entry_id' => $validated['entry_id']]);
        }

        // Upload the file
        $upload = $this->uploadService->uploadFileToRemote(
            upload: $upload,
            file: $request->file('file'),
            ipAddress: $request->ip(),
        );

        if ($upload->isFailed()) {
            return response()->json([
                'success' => false,
                'upload_id' => $upload->id,
                'file_id' => null,
                'file_url' => null,
                'message' => $upload->last_error ?? 'File upload failed.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'upload_id' => $upload->id,
            'file_id' => $upload->remote_file_id,
            'file_url' => null, // URL not stored in Upload model
            'message' => 'File uploaded successfully.',
        ]);
    }
}
