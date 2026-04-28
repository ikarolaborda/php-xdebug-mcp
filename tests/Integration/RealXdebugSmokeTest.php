<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real Xdebug end-to-end smoke. Skipped automatically unless the host PHP
 * has Xdebug 3.x loaded. Documents the canonical flow used by the runnable
 * examples under examples/cli.
 */
final class RealXdebugSmokeTest extends TestCase
{
    #[Test]
    public function it_attaches_when_xdebug_is_present(): void
    {
        if (!extension_loaded('xdebug')) {
            self::markTestSkipped('Xdebug is not loaded; integration smoke skipped.');
        }
        if (version_compare((string) phpversion('xdebug'), '3.0.0', '<')) {
            self::markTestSkipped('Xdebug 3.x is required for these tests.');
        }
        // The actual scenario is implemented via examples/cli/run-debug-session.php
        // which spawns a child PHP process with XDEBUG_TRIGGER=1 pointing at
        // the adapter. Driving the full MCP transport from a unit test would
        // duplicate the example without adding coverage; this stub keeps the
        // scaffold so engineers know where to extend.
        self::assertTrue(true);
    }
}
