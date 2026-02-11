<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\UploadFileRequest;
use App\Http\Requests\App\UploadInitRequest;
use App\Services\TrackAI\UploadService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

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
        $projects = \App\Models\Project::orderBy('name')->get();

        return Inertia::render('app/Uploads', [
            'projects' => $projects,
        ]);
    }

    /**
     * Initialize an upload entry.
     */
    public function init(UploadInitRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $response = $this->uploadService->initUpload(
            userId: $user->id,
            contractId: $validated['contract_id'],
            documentType: $validated['document_type'],
            tags: $validated['tags'] ?? [],
            name: $validated['name'] ?? null,
            remarks: $validated['remarks'] ?? null,
            latitude: $validated['latitude'] ?? 0,
            longitude: $validated['longitude'] ?? 0,
            ipAddress: $request->ip(),
            clientRequestId: $validated['client_request_id'] ?? null,
        );

        return response()->json([
            'success' => $response->success,
            'entry_id' => $response->entryId,
            'message' => $response->message,
        ]);
    }

    /**
     * Upload a file.
     */
    public function file(UploadFileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $response = $this->uploadService->uploadFile(
            userId: $user->id,
            contractId: $validated['contract_id'],
            entryId: $validated['entry_id'],
            file: $request->file('file'),
        );

        return response()->json([
            'success' => $response->success,
            'file_id' => $response->fileId,
            'file_url' => $response->fileUrl,
            'message' => $response->message,
        ]);
    }
}
