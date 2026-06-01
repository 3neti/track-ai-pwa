<?php

declare(strict_types=1);

namespace App\Console\Commands\Lifecycle;

use App\Lifecycle\Output\ConsoleLifecycleOutput;
use App\Lifecycle\Scenarios\LifecycleScenarioEngine;
use App\Lifecycle\Scenarios\LifecycleScenarioRepository;
use App\Lifecycle\Scenarios\LifecycleScenarioRunOptions;
use Illuminate\Console\Command;

class RunLifecycleScenarioCommand extends Command
{
    protected $signature = 'trackai:lifecycle:run
        {scenario? : Scenario key from lifecycle-scenarios config}
        {--list : List available scenarios}
        {--user= : User ID to run as}
        {--project= : Project ID to use}
        {--timeout= : Poll timeout in seconds}
        {--poll= : Poll interval in seconds}
        {--json : Output JSON}';

    protected $description = 'Run a named lifecycle scenario.';

    public function handle(
        LifecycleScenarioEngine $engine,
        LifecycleResultRenderer $renderer,
        LifecycleScenarioRepository $scenarioRepository,
    ): int {
        if ($this->option('list')) {
            return $this->listScenarios($scenarioRepository);
        }

        $scenarioKey = (string) $this->argument('scenario');

        if ($scenarioKey === '') {
            $this->error('Please provide a scenario key or use --list to see available scenarios.');

            return self::FAILURE;
        }

        $options = LifecycleScenarioRunOptions::fromConsoleOptions($this->options());
        $output = new ConsoleLifecycleOutput($this);

        $result = $engine->run(
            scenarioKey: $scenarioKey,
            options: $options,
            output: $output,
        );

        return $renderer->render(
            command: $this,
            payload: $result->payload,
            exitCode: $result->exitCode,
        );
    }

    protected function listScenarios(LifecycleScenarioRepository $scenarioRepository): int
    {
        $scenarios = $scenarioRepository->all();

        if ($scenarios === []) {
            $this->warn('No lifecycle scenarios found.');

            return self::SUCCESS;
        }

        $this->info('Available lifecycle scenarios:');
        $this->newLine();

        foreach ($scenarios as $key => $scenario) {
            $label = $scenarioRepository->labelFor((string) $key, (array) $scenario);
            $mode = $scenario['mode'] ?? 'default';
            $category = $scenario['category'] ?? 'smoke';

            $this->line(sprintf(
                '  <comment>%s</comment> — %s [mode: %s, category: %s]',
                $key,
                $label,
                $mode,
                $category,
            ));

            if (! empty($scenario['description'])) {
                $this->line("    {$scenario['description']}");
            }
        }

        return self::SUCCESS;
    }
}
