<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Infrastructure\SystemClock;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Services\SessionClaimManager;
use PhpXdebugMcp\Services\SessionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SessionResolverTest extends TestCase
{
    private array $pairs = [];

    private function makeSession(SessionRegistry $registry): DbgpSession
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->pairs[] = [$a, $b];
        $session = new DbgpSession($a, 'peer:0', new SystemClock(), new NullLogger());
        $registry->add($session);

        return $session;
    }

    #[Test]
    public function it_auto_resolves_when_exactly_one_session_is_claimed(): void
    {
        $registry = new SessionRegistry();
        $claims = new SessionClaimManager(new SystemClock(), false);
        $a = $this->makeSession($registry);
        $b = $this->makeSession($registry);
        $claims->claim($a->adapterId, 'X');
        $resolver = new SessionResolver($registry, $claims);
        self::assertSame($a, $resolver->resolve(null));
    }

    #[Test]
    public function it_returns_session_ambiguous_when_multiple_claimed(): void
    {
        $registry = new SessionRegistry();
        $claims = new SessionClaimManager(new SystemClock(), false);
        $a = $this->makeSession($registry);
        $b = $this->makeSession($registry);
        $claims->claim($a->adapterId, 'X');
        $claims->claim($b->adapterId, 'Y');
        $resolver = new SessionResolver($registry, $claims);
        try {
            $resolver->resolve(null);
            self::fail('expected SessionAmbiguous');
        } catch (AdapterException $e) {
            self::assertSame(AdapterErrorCode::SessionAmbiguous, $e->errorCode);
        }
    }

    #[Test]
    public function it_resolves_a_specific_id_even_without_claim(): void
    {
        $registry = new SessionRegistry();
        $claims = new SessionClaimManager(new SystemClock(), false);
        $a = $this->makeSession($registry);
        $resolver = new SessionResolver($registry, $claims);
        self::assertSame($a, $resolver->resolve($a->adapterId));
    }

    protected function tearDown(): void
    {
        foreach ($this->pairs as [$x, $y]) {
            if (is_resource($x)) {
                fclose($x);
            }
            if (is_resource($y)) {
                fclose($y);
            }
        }
        $this->pairs = [];
    }
}
