<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

final readonly class LifecycleScenarioEngineResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $exitCode,
        public array $payload,
    ) {}
}
