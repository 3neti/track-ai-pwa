<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

use InvalidArgumentException;

final class LifecycleScenarioRepository
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $scenarios = config('lifecycle-scenarios.scenarios', []);

        if (! is_array($scenarios)) {
            throw new InvalidArgumentException('Lifecycle scenarios config must be an array.');
        }

        $normalized = [];

        foreach ($scenarios as $key => $scenario) {
            if (! is_string($key) || ! is_array($scenario)) {
                continue;
            }

            $normalized[$key] = $this->normalize($key, $scenario);
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $scenarioKey): ?array
    {
        return $this->all()[$scenarioKey] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrFail(string $scenarioKey): array
    {
        $scenario = $this->find($scenarioKey);

        if ($scenario === null) {
            throw new InvalidArgumentException("Unknown scenario: {$scenarioKey}");
        }

        return $scenario;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function byCategory(string $category): array
    {
        return array_filter(
            $this->all(),
            fn (array $scenario): bool => ($scenario['category'] ?? null) === $category,
        );
    }

    public function labelFor(string $scenarioKey, array $scenario): string
    {
        return (string) data_get($scenario, 'label', $scenarioKey);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function normalize(string $key, array $scenario): array
    {
        $tags = data_get($scenario, 'tags', []);

        if (! is_array($tags)) {
            $tags = [$tags];
        }

        $scenario['key'] = $key;
        $scenario['label'] = $this->labelFor($key, $scenario);
        $scenario['category'] = (string) data_get($scenario, 'category', 'smoke');
        $scenario['mode'] = (string) data_get($scenario, 'mode', 'default');
        $scenario['risk'] = (string) data_get($scenario, 'risk', 'medium');
        $scenario['description'] = (string) data_get($scenario, 'description', '');
        $scenario['tags'] = array_values(array_filter(
            array_map('strval', $tags),
            fn (string $tag): bool => trim($tag) !== '',
        ));

        return $scenario;
    }
}
