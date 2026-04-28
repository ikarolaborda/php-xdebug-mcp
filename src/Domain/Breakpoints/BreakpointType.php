<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Breakpoints;

enum BreakpointType: string
{
    case Line = 'line';
    case Conditional = 'conditional';
    case Exception = 'exception';
    case Call = 'call';
    case Return_ = 'return';
    case Watch = 'watch';

    public function requiresFile(): bool
    {
        return $this === self::Line || $this === self::Conditional;
    }

    public function requiresExpression(): bool
    {
        return $this === self::Conditional || $this === self::Watch;
    }

    public function requiresFunction(): bool
    {
        return $this === self::Call || $this === self::Return_;
    }

    public function requiresException(): bool
    {
        return $this === self::Exception;
    }
}
