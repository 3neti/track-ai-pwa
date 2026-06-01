<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

use App\Lifecycle\Output\LifecycleOutputContract;
use App\Lifecycle\Runners\ScenarioRunContext;
use App\Lifecycle\Runners\ScenarioRunnerResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class LifecycleScenarioEngine
{
    public function __construct(
        private readonly LifecycleScenarioRepository $scenarioRepository,
        private readonly LifecycleScenarioBootstrapper $bootstrapper,
        private readonly ScenarioRunnerResolver $resolver,
    ) {}

    public function run(
        string $scenarioKey,
        LifecycleScenarioRunOptions $options,
        LifecycleOutputContract $output,
    ): LifecycleScenarioEngineResult {
        try {
            $scenario = $this->scenarioRepository->findOrFail($scenarioKey);
        } catch (InvalidArgumentException $e) {
            return new LifecycleScenarioEngineResult(
                exitCode: Command::FAILURE,
                payload: [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'scenario' => $scenarioKey,
                ],
            );
        }

        $scenario = array_replace_recursive(
            (array) config('lifecycle-scenarios.defaults', []),
            $scenario,
        );

        try {
            $resolution = $this->resolver->resolve($scenario);
        } catch (RuntimeException $e) {
            return new LifecycleScenarioEngineResult(
                exitCode: Command::FAILURE,
                payload: [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'scenario' => $scenarioKey,
                ],
            );
        }

        if (! $output->isJson()) {
            $output->info("Running scenario: {$scenarioKey}");
            $output->line("Mode: {$resolution->mode}");
            $output->line('Bootstrapping...');
        }

        try {
            $bootstrap = $this->bootstrapper->bootstrap(
                scenario: $resolution->scenario,
                userIdOption: $options->userId,
                projectIdOption: $options->projectId,
                timeoutOption: $options->timeout,
                pollOption: $options->poll,
            );
        } catch (RuntimeException $e) {
            return new LifecycleScenarioEngineResult(
                exitCode: Command::FAILURE,
                payload: [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'scenario' => $scenarioKey,
                ],
            );
        }

        if (! $output->isJson()) {
            $output->line("User: {$bootstrap->user->name} (ID: {$bootstrap->user->id})");
            $output->line("Project: {$bootstrap->project->name}");
            $output->line("Contract: {$bootstrap->contractId}");
        }

        $context = new ScenarioRunContext(
            output: $output,
            scenarioKey: $scenarioKey,
            scenario: $resolution->scenario,
            user: $bootstrap->user,
            project: $bootstrap->project,
            contractId: $bootstrap->contractId,
            timeout: $bootstrap->timeout,
            poll: $bootstrap->poll,
            maxPolls: $bootstrap->maxPolls,
            bucket: $options->bucket,
        );

        $result = $resolution->runner->run($context);

        return new LifecycleScenarioEngineResult(
            exitCode: $result->exitCode,
            payload: $result->payload,
        );
    }
}
