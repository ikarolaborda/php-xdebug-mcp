<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp;

enum SafetyMode: string
{
    case Observe = 'observe';
    case Control = 'control';
    case FullControl = 'full_control';

    public function allowsBreakpoints(): bool
    {
        return $this !== self::Observe;
    }

    public function allowsStepping(): bool
    {
        return $this !== self::Observe;
    }

    public function allowsInspection(): bool
    {
        return $this !== self::Observe;
    }

    public function allowsEval(): bool
    {
        return $this === self::FullControl;
    }

    public function allowsPropertyMutation(): bool
    {
        return $this === self::FullControl;
    }

    public function allowsStdin(): bool
    {
        return $this === self::FullControl;
    }
}
