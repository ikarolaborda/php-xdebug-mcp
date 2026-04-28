<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

enum FrameKind: string
{
    case File = 'file';
    case Eval = 'eval';
    case Internal = 'internal';
    case Unknown = 'unknown';
}
