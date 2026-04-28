<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PhpXdebugMcp\Mcp\SafetyMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafetyModeTest extends TestCase
{
    #[Test]
    public function it_blocks_mutations_in_observe_mode(): void
    {
        $m = SafetyMode::Observe;
        self::assertFalse($m->allowsBreakpoints());
        self::assertFalse($m->allowsStepping());
        self::assertFalse($m->allowsInspection());
        self::assertFalse($m->allowsEval());
        self::assertFalse($m->allowsPropertyMutation());
        self::assertFalse($m->allowsStdin());
    }

    #[Test]
    public function it_allows_inspection_and_stepping_in_control_mode(): void
    {
        $m = SafetyMode::Control;
        self::assertTrue($m->allowsBreakpoints());
        self::assertTrue($m->allowsStepping());
        self::assertTrue($m->allowsInspection());
        self::assertFalse($m->allowsEval());
        self::assertFalse($m->allowsPropertyMutation());
        self::assertFalse($m->allowsStdin());
    }

    #[Test]
    public function it_allows_everything_in_full_control_mode(): void
    {
        $m = SafetyMode::FullControl;
        self::assertTrue($m->allowsBreakpoints());
        self::assertTrue($m->allowsStepping());
        self::assertTrue($m->allowsInspection());
        self::assertTrue($m->allowsEval());
        self::assertTrue($m->allowsPropertyMutation());
        self::assertTrue($m->allowsStdin());
    }
}
