<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use PhpXdebugMcp\Domain\Breakpoints\BreakpointDefinition;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointScope;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointType;
use PhpXdebugMcp\Domain\Breakpoints\HitCondition;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BreakpointDefinitionTest extends TestCase
{
    #[Test]
    public function it_creates_a_valid_line_breakpoint(): void
    {
        $def = BreakpointDefinition::create(
            type: BreakpointType::Line,
            scope: BreakpointScope::Persistent,
            enabled: true,
            temporary: false,
            localPath: '/app/index.php',
            remoteUri: 'file:///app/index.php',
            lineno: 42,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: '2026-04-28T09:00:00Z',
        );
        self::assertSame(BreakpointType::Line, $def->type);
        self::assertSame(42, $def->lineno);
    }

    #[Test]
    public function it_rejects_a_line_breakpoint_without_lineno(): void
    {
        try {
            BreakpointDefinition::create(
                type: BreakpointType::Line,
                scope: BreakpointScope::Persistent,
                enabled: true,
                temporary: false,
                localPath: '/x.php',
                remoteUri: null,
                lineno: null,
                functionName: null,
                exceptionName: null,
                expression: null,
                hitValue: null,
                hitCondition: null,
                now: 'now',
            );
            self::fail('expected validation failure');
        } catch (AdapterException $e) {
            self::assertSame(AdapterErrorCode::BreakpointValidationFailed, $e->errorCode);
        }
    }

    #[Test]
    public function it_rejects_conditional_without_expression(): void
    {
        $this->expectException(AdapterException::class);
        BreakpointDefinition::create(
            type: BreakpointType::Conditional,
            scope: BreakpointScope::Session,
            enabled: true,
            temporary: false,
            localPath: '/x.php',
            remoteUri: null,
            lineno: 10,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: 'now',
        );
    }

    #[Test]
    public function it_applies_a_patch_to_lineno_and_validates(): void
    {
        $def = BreakpointDefinition::create(
            type: BreakpointType::Line,
            scope: BreakpointScope::Persistent,
            enabled: true,
            temporary: false,
            localPath: '/x.php',
            remoteUri: null,
            lineno: 5,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: 'now',
        );
        $next = $def->applyPatch(['lineno' => 12, 'enabled' => false], 'later');
        self::assertSame(12, $next->lineno);
        self::assertFalse($next->enabled);
        self::assertSame('later', $next->updatedAt);
    }

    #[Test]
    public function it_rejects_a_patch_that_makes_the_breakpoint_invalid(): void
    {
        $def = BreakpointDefinition::create(
            type: BreakpointType::Line,
            scope: BreakpointScope::Persistent,
            enabled: true,
            temporary: false,
            localPath: '/x.php',
            remoteUri: null,
            lineno: 5,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: 'now',
        );
        $this->expectException(AdapterException::class);
        $def->applyPatch(['lineno' => 0], 'later');
    }
}
