<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

use App\Lifecycle\Output\LifecycleOutputContract;
use App\Models\Project;
use App\Models\User;

final readonly class ScenarioRunContext
{
    /**
     * @param  array<string, mixed>  $scenario
     */
    public function __construct(
        public LifecycleOutputContract $output,
        public string $scenarioKey,
        public array $scenario,
        public User $user,
        public Project $project,
        public string $contractId,
        public int $timeout,
        public int $poll,
        public int $maxPolls,
    ) {}

    public function mode(): string
    {
        return (string) ($this->scenario['mode'] ?? 'default');
    }

    public function label(): string
    {
        return (string) ($this->scenario['label'] ?? $this->scenarioKey);
    }

    public function remarks(): string
    {
        return (string) ($this->scenario['remarks'] ?? '');
    }

    public function currentMilestone(): string
    {
        return (string) ($this->scenario['current_milestone'] ?? '');
    }
}
