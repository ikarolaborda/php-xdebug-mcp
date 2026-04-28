<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Infrastructure;

final class SystemClock implements Clock
{
    public function nowMicrotime(): float
    {
        return microtime(true);
    }

    public function nowIso8601(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
