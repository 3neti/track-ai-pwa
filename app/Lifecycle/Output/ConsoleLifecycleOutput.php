<?php

declare(strict_types=1);

namespace App\Lifecycle\Output;

use Illuminate\Console\Command;

final readonly class ConsoleLifecycleOutput implements LifecycleOutputContract
{
    public function __construct(
        private Command $command,
    ) {}

    public function line(string $message): void
    {
        $this->command->line($message);
    }

    public function info(string $message): void
    {
        $this->command->info($message);
    }

    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    public function error(string $message): void
    {
        $this->command->error($message);
    }

    public function isJson(): bool
    {
        return (bool) $this->command->option('json');
    }
}
