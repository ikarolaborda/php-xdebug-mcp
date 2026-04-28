<?php

declare(strict_types=1);

namespace Tests\Contract;

use PhpXdebugMcp\Dbgp\DbgpListener;
use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Diagnostics\SessionWarning;
use PhpXdebugMcp\Domain\Events\EventKind;
use PhpXdebugMcp\Domain\Paths\PathMappingRule;
use PhpXdebugMcp\Infrastructure\SystemClock;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\BreakpointStore;
use PhpXdebugMcp\Services\EventRecorder;
use PhpXdebugMcp\Services\OutputBufferStore;
use PhpXdebugMcp\Services\PathMapper;
use PhpXdebugMcp\Services\SessionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fixtures\FakeXdebugEngine;

/**
 * Verifies that when a session connects with a fileuri that does not match
 * any configured path rule, a structured PATH_RULE_MISSING warning is
 * attached to the session and a corresponding event is recorded.
 */
final class PathRuleMissingDiagnosticsTest extends TestCase
{
    private function buildRuntime(PathMapper $mapper, EventRecorder $events): DbgpRuntime
    {
        $clock = new SystemClock();
        $logger = new NullLogger();
        $registry = new SessionRegistry();

        return new DbgpRuntime(
            listener: new DbgpListener('127.0.0.1', 0, $logger, false, []),
            registry: $registry,
            breakpoints: new BreakpointStore(),
            events: $events,
            output: new OutputBufferStore($clock),
            pathMapper: $mapper,
            audit: new AuditLogger($logger),
            logger: $logger,
            clock: $clock,
            maxChildren: 100,
            maxData: 4096,
            maxDepth: 3,
            workspaceRoots: [sys_get_temp_dir()],
        );
    }

    private function makeSessionWithInit(string $fileUri): DbgpSession
    {
        [$ide, $engine] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $session = new DbgpSession($ide, 'peer:0', new SystemClock(), new NullLogger());
        $fake = new FakeXdebugEngine($engine);
        $fake->sendInit(fileUri: $fileUri);
        $session->readPending();
        foreach ($session->drainPackets() as $packet) {
            if ($packet['kind'] === 'init') {
                $session->init = $packet['data'];
            }
        }
        $fake->close();

        return $session;
    }

    #[Test]
    public function it_emits_path_rule_missing_warning_when_init_fileuri_is_uncovered(): void
    {
        $events = new EventRecorder(new SystemClock());
        $mapper = new PathMapper([
            new PathMappingRule(localRoot: '/home/me/proj', remoteRoot: '/var/www/html', label: 'docker'),
        ]);
        $runtime = $this->buildRuntime($mapper, $events);

        $session = $this->makeSessionWithInit('file:///srv/legacy/index.php');
        $runtime->emitInversePathDiagnostic($session);

        self::assertNotEmpty($session->warnings);
        $warning = array_values($session->warnings)[0];
        self::assertSame(SessionWarning::CODE_PATH_RULE_MISSING, $warning->code);
        self::assertSame('file:///srv/legacy/index.php', $warning->context['remote_fileuri']);

        $eventKinds = array_map(static fn ($e): string => $e->kind->value, $events->recent($session->adapterId));
        self::assertContains(EventKind::PathRuleMissing->value, $eventKinds);
    }

    #[Test]
    public function it_does_not_emit_warning_when_no_rules_are_configured(): void
    {
        $events = new EventRecorder(new SystemClock());
        $runtime = $this->buildRuntime(new PathMapper([]), $events);

        $session = $this->makeSessionWithInit('file:///srv/legacy/index.php');
        $runtime->emitInversePathDiagnostic($session);

        self::assertEmpty($session->warnings);
    }

    #[Test]
    public function it_does_not_emit_warning_when_a_matching_rule_exists(): void
    {
        $events = new EventRecorder(new SystemClock());
        $mapper = new PathMapper([
            new PathMappingRule(localRoot: '/home/me/proj', remoteRoot: '/var/www/html', label: 'docker'),
        ]);
        $runtime = $this->buildRuntime($mapper, $events);

        $session = $this->makeSessionWithInit('file:///var/www/html/app/Index.php');
        $runtime->emitInversePathDiagnostic($session);

        self::assertEmpty($session->warnings);
    }
}
