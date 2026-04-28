<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Infrastructure;

interface Clock
{
    public function nowMicrotime(): float;

    public function nowIso8601(): string;
}
