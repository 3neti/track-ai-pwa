<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Saras\SarasProjectContextResolver;
use App\Services\TrackAI\ContractService;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SarasProjectSelectionController extends Controller
{
    public function __construct(
        protected SarasProjectContextResolver $contextResolver,
        protected ContractService $contractService,
        protected ProjectProgressService $projectProgressService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('app/ProjectContext', [
            'activeContext' => $this->contextResolver->resolve($user)->toArray(),
            'projects' => $this->contextResolver->availableProjectOptions($user),
            'defaultProjectId' => config('saras.project_id'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'saras_project_id' => ['required', 'string', 'max:120'],
        ]);

        $context = $this->contextResolver->selectProject(
            user: $request->user(),
            projectId: $validated['saras_project_id'],
            persist: true,
        );

        $this->syncSelectedProjectResources($request, $context->projectId);

        return redirect()
            ->route('app.project-context')
            ->with('status', sprintf('Project context switched to %s.', $context->projectName ?: $context->projectId));
    }

    protected function syncSelectedProjectResources(Request $request, ?string $projectId): void
    {
        try {
            $this->contractService->syncContractsFromSaras();

            $project = Project::where('external_id', $projectId)->first()
                ?? Project::where('contract_id', $projectId)->first()
                ?? Project::query()->first();

            if ($project) {
                $this->projectProgressService->syncProjectProgressFromSaras($request->user(), $project);
            }
        } catch (\Throwable $e) {
            Log::warning('Saras project switch resource sync failed', [
                'user_id' => $request->user()?->id,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
