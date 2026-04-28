<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Infrastructure;

/**
 * Adapter-internal identifier generator. We do not rely on any external uuid
 * libraries to keep the dependency surface minimal.
 */
final class Ids
{
    public static function adapterSessionId(): string
    {
        return 'sess_' . self::shortRandom(10);
    }

    public static function adapterBreakpointId(): string
    {
        return 'bp_' . self::shortRandom(10);
    }

    public static function shortRandom(int $bytes = 8): string
    {
        return bin2hex(random_bytes(max(1, $bytes / 2 | 0)));
    }
}
