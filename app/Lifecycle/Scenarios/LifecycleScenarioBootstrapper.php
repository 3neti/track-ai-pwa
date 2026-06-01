<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

use App\Models\Project;
use App\Models\User;
use RuntimeException;

final class LifecycleScenarioBootstrapper
{
    /**
     * @param  array<string, mixed>  $scenario
     */
    public function bootstrap(
        array $scenario,
        ?int $userIdOption = null,
        ?int $projectIdOption = null,
        ?int $timeoutOption = null,
        ?int $pollOption = null,
    ): LifecycleScenarioBootstrapResult {
        $userId = $userIdOption
            ?? data_get($scenario, 'user_id')
            ?? config('lifecycle-scenarios.defaults.user_id', 1);

        $projectId = $projectIdOption
            ?? data_get($scenario, 'project_id')
            ?? config('lifecycle-scenarios.defaults.project_id');

        $timeout = $timeoutOption
            ?? data_get($scenario, 'timeout')
            ?? config('lifecycle-scenarios.defaults.timeout', 300);

        $poll = max(1, $pollOption
            ?? data_get($scenario, 'poll')
            ?? config('lifecycle-scenarios.defaults.poll', 10));

        $maxPolls = (int) ceil($timeout / max(1, $poll));

        $user = User::find($userId);

        if (! $user) {
            throw new RuntimeException("Unable to resolve lifecycle user [{$userId}].");
        }

        $project = $this->resolveProject($projectId);
        $contractId = $project->contract_id ?: config('saras.default_contract_id', '');

        return new LifecycleScenarioBootstrapResult(
            user: $user,
            project: $project,
            contractId: $contractId,
            timeout: (int) $timeout,
            poll: (int) $poll,
            maxPolls: $maxPolls,
        );
    }

    private function resolveProject(mixed $projectId): Project
    {
        if ($projectId !== null) {
            $project = Project::find($projectId);

            if (! $project) {
                throw new RuntimeException("Unable to resolve lifecycle project [{$projectId}].");
            }

            return $project;
        }

        $project = Project::where('status', 'active')->first();

        if (! $project) {
            throw new RuntimeException('No active project found for lifecycle scenario. Provide --project= or seed a project.');
        }

        return $project;
    }
}
