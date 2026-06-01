<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

use App\Models\Project;
use App\Models\User;

final readonly class LifecycleScenarioBootstrapResult
{
    public function __construct(
        public User $user,
        public Project $project,
        public string $contractId,
        public int $timeout,
        public int $poll,
        public int $maxPolls,
    ) {}
}
