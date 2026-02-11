<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Services\Saras\SarasClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        protected SarasClient $sarasClient,
    ) {}

    /**
     * Display the projects page.
     */
    public function index(): Response
    {
        $projects = Project::orderBy('name')->get();

        return Inertia::render('app/Projects', [
            'projects' => $projects,
        ]);
    }

    /**
     * Sync projects from Saras.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        $sarasProjects = $this->sarasClient->getAllAssignedProjects((string) $user->id);

        $syncedProjects = [];

        foreach ($sarasProjects as $sarasProject) {
            $project = Project::updateOrCreate(
                ['external_id' => $sarasProject->externalId],
                [
                    'name' => $sarasProject->name,
                    'description' => $sarasProject->description,
                    'cached_at' => now(),
                ]
            );

            $syncedProjects[] = $project;
        }

        AuditLog::log($user->id, 'projects_sync', null, [
            'synced_count' => count($syncedProjects),
        ]);

        return response()->json([
            'success' => true,
            'projects' => $syncedProjects,
            'message' => count($syncedProjects).' projects synced successfully',
        ]);
    }
}
