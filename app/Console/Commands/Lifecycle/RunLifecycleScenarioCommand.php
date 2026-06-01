<?php

declare(strict_types=1);

namespace App\Console\Commands\Lifecycle;

use App\Lifecycle\Output\ConsoleLifecycleOutput;
use App\Lifecycle\Output\SarasApiTracer;
use App\Lifecycle\Output\TracingLifecycleOutput;
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
        {--json : Output JSON}
        {--trace : Show API call traces}
        {--report : Show full diagnostic report (flow, artifacts, payloads, action items)}
        {--bucket= : Path to folder with previous/ and current/ subfolders for file uploads}';

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

        $tracer = app(SarasApiTracer::class);
        $consoleOutput = new ConsoleLifecycleOutput($this);
        $needsTracer = $options->verbose || $options->report;

        if ($needsTracer) {
            $tracer->enable();
        }

        if ($options->verbose) {
            $output = new TracingLifecycleOutput($consoleOutput, $tracer);
        } else {
            $output = $consoleOutput;
        }

        $result = $engine->run(
            scenarioKey: $scenarioKey,
            options: $options,
            output: $output,
        );

        // Flush any remaining traces
        if ($output instanceof TracingLifecycleOutput) {
            $output->flushRemaining();
        }

        $exitCode = $renderer->render(
            command: $this,
            payload: $result->payload,
            exitCode: $result->exitCode,
            tracer: $options->verbose ? $tracer : null,
        );

        // Render full diagnostic report
        if ($options->report && ! $options->json) {
            app(LifecycleReportRenderer::class)->render(
                command: $this,
                payload: $result->payload,
                tracer: $tracer,
            );
        }

        return $exitCode;
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
