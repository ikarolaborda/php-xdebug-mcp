<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Breakpoints;

/**
 * DBGp 7.6.1 hit_condition values: ">=", "==", "%". See
 * https://xdebug.org/docs/dbgp .
 */
enum HitCondition: string
{
    case GreaterOrEqual = '>=';
    case Equal = '==';
    case Modulo = '%';
}
