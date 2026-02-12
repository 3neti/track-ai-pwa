<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
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
     *
     * Fetches all pages of projects and syncs to local database.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $syncedProjects = [];
            $page = 1;
            $perPage = 50;

            do {
                $response = $this->sarasClient->getProjectsForUser($page, $perPage);

                foreach ($response->projects as $sarasProject) {
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

                $page++;
            } while ($page <= $response->totalPages);

            AuditLog::log($user->id, 'projects_sync', null, [
                'synced_count' => count($syncedProjects),
            ]);

            return response()->json([
                'success' => true,
                'projects' => $syncedProjects,
                'message' => count($syncedProjects).' projects synced successfully',
            ]);

        } catch (SarasApiException $e) {
            return response()->json([
                'success' => false,
                'projects' => [],
                'message' => 'Failed to sync projects: '.$e->getMessage(),
            ], 500);
        }
    }
}
