<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

interface ScenarioRunnerContract
{
    public function run(ScenarioRunContext $context): ScenarioRunResult;
}
