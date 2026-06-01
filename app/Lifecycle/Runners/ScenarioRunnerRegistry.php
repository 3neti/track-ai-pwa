<?php

declare(strict_types=1);

namespace App\Lifecycle\Runners;

use RuntimeException;

final class ScenarioRunnerRegistry
{
    public function has(?string $mode): bool
    {
        return in_array($mode, [null, 'default', 'full_lifecycle'], true);
    }

    public function for(?string $mode): ScenarioRunnerContract
    {
        return match ($mode) {
            null, 'default' => app(DefaultProgressScenarioRunner::class),
            'full_lifecycle' => app(FullLifecycleScenarioRunner::class),
            default => throw new RuntimeException("No lifecycle scenario runner registered for mode [{$mode}]."),
        };
    }
}
