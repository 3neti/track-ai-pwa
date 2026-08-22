<?php

declare(strict_types=1);

namespace App\Lifecycle\Scenarios;

final readonly class LifecycleScenarioRunOptions
{
    public function __construct(
        public ?int $userId = null,
        public ?int $projectId = null,
        public ?string $contractId = null,
        public ?int $timeout = null,
        public ?int $poll = null,
        public bool $json = false,
        public bool $verbose = false,
        public bool $report = false,
        public ?string $bucket = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromConsoleOptions(array $options): self
    {
        return new self(
            userId: self::intOrNull($options['user'] ?? null),
            projectId: self::intOrNull($options['project'] ?? null),
            contractId: self::stringOrNull($options['contract'] ?? null),
            timeout: self::intOrNull($options['timeout'] ?? null),
            poll: self::intOrNull($options['poll'] ?? null),
            json: (bool) ($options['json'] ?? false),
            verbose: (bool) ($options['trace'] ?? false),
            report: (bool) ($options['report'] ?? false),
            bucket: self::stringOrNull($options['bucket'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === null) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
