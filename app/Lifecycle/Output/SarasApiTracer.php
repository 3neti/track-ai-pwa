<?php

declare(strict_types=1);

namespace App\Lifecycle\Output;

final class SarasApiTracer
{
    /** @var array<SarasApiTrace> */
    private array $traces = [];

    private bool $enabled = false;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function record(SarasApiTrace $trace): void
    {
        if ($this->enabled) {
            $this->traces[] = $trace;
        }
    }

    /**
     * @return array<SarasApiTrace>
     */
    public function all(): array
    {
        return $this->traces;
    }

    public function count(): int
    {
        return count($this->traces);
    }

    public function totalDurationMs(): float
    {
        return array_sum(array_map(fn (SarasApiTrace $t) => $t->durationMs, $this->traces));
    }

    /**
     * @return array<string, int>
     */
    public function endpointCounts(): array
    {
        $counts = [];

        foreach ($this->traces as $trace) {
            $key = "{$trace->method} {$trace->endpoint}";
            // Strip query params for grouping
            $key = preg_replace('/\?.*$/', '', $key);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function clear(): void
    {
        $this->traces = [];
    }
}
