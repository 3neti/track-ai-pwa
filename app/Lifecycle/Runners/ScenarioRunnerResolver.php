<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

use RuntimeException;

final class ScenarioRunnerResolver
{
    public function __construct(
        private readonly ScenarioRunnerRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $scenario
     */
    public function resolve(array $scenario): ScenarioRunnerResolution
    {
        $mode = (string) data_get($scenario, 'mode', 'default');

        if (! $this->registry->has($mode)) {
            throw new RuntimeException("No lifecycle scenario runner registered for mode [{$mode}].");
        }

        return new ScenarioRunnerResolution(
            mode: $mode,
            scenario: $scenario,
            runner: $this->registry->for($mode),
        );
    }
}
