<?php

declare(strict_types=1);

namespace App\Lifecycle\Output;

final readonly class SarasApiTrace
{
    public function __construct(
        public string $method,
        public string $endpoint,
        public int $status,
        public float $durationMs,
        public array $requestSummary = [],
        public array $responseSummary = [],
        public ?string $error = null,
        public array $rawRequest = [],
        public array $rawResponse = [],
    ) {}

    /**
     * Format as a compact console trace block.
     *
     * @return array<string>
     */
    public function toConsoleLines(): array
    {
        $statusLabel = $this->status >= 200 && $this->status < 300
            ? "{$this->status} OK"
            : "{$this->status} ERROR";

        $duration = number_format($this->durationMs, 0);

        $lines = [];
        $lines[] = "  \u250c\u2500 {$this->method} {$this->endpoint}";

        foreach ($this->requestSummary as $key => $value) {
            $lines[] = "  \u2502  {$key}: {$this->truncate($value)}";
        }

        $lines[] = "  \u2502  \u23f1 {$duration}ms \u2192 {$statusLabel}";

        if ($this->error) {
            $lines[] = "  \u2502  error: {$this->truncate($this->error, 120)}";
        }

        foreach ($this->responseSummary as $key => $value) {
            $lines[] = "  \u2502  {$key}: {$this->truncate($value)}";
        }

        $lines[] = '  └─';

        return $lines;
    }

    private function truncate(mixed $value, int $max = 80): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        $value = (string) $value;

        return strlen($value) > $max
            ? substr($value, 0, $max).'...'
            : $value;
    }
}
