<?php

declare(strict_types=1);

namespace Tests\Contract;

use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use PhpXdebugMcp\Infrastructure\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fixtures\FakeXdebugEngine;

/**
 * Drives a DbgpSession against a scripted FakeXdebugEngine using a unix
 * socket pair. This is a contract test: it pins down the wire-level
 * behaviour of the session/codec/encoder triangle without requiring a real
 * Xdebug install.
 */
final class SessionLifecycleTest extends TestCase
{
    #[Test]
    public function it_negotiates_features_and_runs_a_breakpoint_set_roundtrip(): void
    {
        [$ide, $engine] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $session = new DbgpSession($ide, 'peer:0', new SystemClock(), new NullLogger());
        $fake = new FakeXdebugEngine($engine);

        $fake->expect('breakpoint_set', static function (int $txid, array $args): string {
            self::assertSame('line', $args['t']);
            self::assertSame('file:///app/index.php', $args['f']);
            self::assertSame('12', $args['n']);

            return '<response xmlns="urn:debugger_protocol_v1" command="breakpoint_set" transaction_id="'
                . $txid . '" id="ENGINE-1" state="enabled" resolved="resolved"/>';
        });

        $captured = null;
        $session->sendCommand(
            'breakpoint_set',
            ['t' => 'line', 'f' => 'file:///app/index.php', 'n' => 12, 's' => 'enabled', 'r' => '0'],
            null,
            isContinuation: false,
            isBreak: false,
            resolver: function (array $r) use (&$captured): void {
                $captured = $r;
            },
        );

        // Pump the fake then drain on the IDE side.
        $fake->pump();
        $session->readPending();
        foreach ($session->drainPackets() as $packet) {
            if ($packet['kind'] === 'response') {
                $session->applyResponseStateTransition($packet['data']);
                $session->deliverResponse($packet['data']);
            }
        }
        self::assertNotNull($captured);
        self::assertSame('ENGINE-1', $captured['attrs']['id'] ?? null);
        $fake->close();
    }

    #[Test]
    public function it_marks_the_session_disconnected_when_the_engine_closes(): void
    {
        [$ide, $engine] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $session = new DbgpSession($ide, 'peer:0', new SystemClock(), new NullLogger());
        @fclose($engine);

        $session->readPending();
        self::assertSame(SessionState::Disconnected, $session->state);
    }
}
