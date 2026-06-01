<?php

declare(strict_types=1);

namespace App\Lifecycle\Output;

/**
 * Decorates a LifecycleOutputContract to stream API traces
 * inline after each output call when verbose mode is enabled.
 */
final class TracingLifecycleOutput implements LifecycleOutputContract
{
    private int $lastTraceIndex = 0;

    public function __construct(
        private readonly LifecycleOutputContract $inner,
        private readonly SarasApiTracer $tracer,
    ) {}

    public function line(string $message): void
    {
        $this->flushNewTraces();
        $this->inner->line($message);
    }

    public function info(string $message): void
    {
        $this->flushNewTraces();
        $this->inner->info($message);
    }

    public function warn(string $message): void
    {
        $this->flushNewTraces();
        $this->inner->warn($message);
    }

    public function error(string $message): void
    {
        $this->flushNewTraces();
        $this->inner->error($message);
    }

    public function isJson(): bool
    {
        return $this->inner->isJson();
    }

    /**
     * Flush any API traces that have been recorded since the last flush.
     */
    private function flushNewTraces(): void
    {
        $all = $this->tracer->all();

        while ($this->lastTraceIndex < count($all)) {
            $trace = $all[$this->lastTraceIndex];

            foreach ($trace->toConsoleLines() as $line) {
                $this->inner->line($line);
            }

            $this->lastTraceIndex++;
        }
    }

    /**
     * Flush any remaining traces (call at the end of a scenario).
     */
    public function flushRemaining(): void
    {
        $this->flushNewTraces();
    }
}
