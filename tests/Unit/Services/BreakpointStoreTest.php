<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PhpXdebugMcp\Domain\Breakpoints\BreakpointDefinition;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointScope;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointType;
use PhpXdebugMcp\Services\BreakpointStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BreakpointStoreTest extends TestCase
{
    private function persistentLine(): BreakpointDefinition
    {
        return BreakpointDefinition::create(
            type: BreakpointType::Line,
            scope: BreakpointScope::Persistent,
            enabled: true,
            temporary: false,
            localPath: '/x.php',
            remoteUri: 'file:///x.php',
            lineno: 1,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: 'now',
        );
    }

    #[Test]
    public function it_keeps_persistent_breakpoints_across_sessions(): void
    {
        $store = new BreakpointStore();
        $def = $this->persistentLine();
        $store->addPersistent($def);
        self::assertSame($def, $store->forSession('session-A')[$def->adapterId]);
        self::assertSame($def, $store->forSession('session-B')[$def->adapterId]);
    }

    #[Test]
    public function it_keeps_session_scoped_breakpoints_isolated(): void
    {
        $store = new BreakpointStore();
        $def = BreakpointDefinition::create(
            type: BreakpointType::Line,
            scope: BreakpointScope::Session,
            enabled: true,
            temporary: true,
            localPath: '/x.php',
            remoteUri: 'file:///x.php',
            lineno: 1,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: 'now',
        );
        $store->addSessionScoped('A', $def);
        self::assertArrayHasKey($def->adapterId, $store->forSession('A'));
        self::assertArrayNotHasKey($def->adapterId, $store->forSession('B'));
    }

    #[Test]
    public function it_drops_session_scoped_breakpoints_on_session_end(): void
    {
        $store = new BreakpointStore();
        $def = BreakpointDefinition::create(
            type: BreakpointType::Line,
            scope: BreakpointScope::Session,
            enabled: true,
            temporary: true,
            localPath: '/x.php',
            remoteUri: 'file:///x.php',
            lineno: 1,
            functionName: null,
            exceptionName: null,
            expression: null,
            hitValue: null,
            hitCondition: null,
            now: 'now',
        );
        $store->addSessionScoped('A', $def);
        $store->dropSession('A');
        self::assertArrayNotHasKey($def->adapterId, $store->forSession('A'));
    }
}
