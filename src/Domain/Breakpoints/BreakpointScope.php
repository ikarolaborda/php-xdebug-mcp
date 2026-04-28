<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Breakpoints;

enum BreakpointScope: string
{
    case Persistent = 'persistent';
    case Session = 'session';
}
