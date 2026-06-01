<?php

declare(strict_types=1);

namespace App\Lifecycle\Output;

interface LifecycleOutputContract
{
    public function line(string $message): void;

    public function info(string $message): void;

    public function warn(string $message): void;

    public function error(string $message): void;

    public function isJson(): bool;
}
