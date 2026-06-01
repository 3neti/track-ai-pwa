<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

final readonly class ScenarioRunResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $exitCode,
        public array $payload,
    ) {}
}
