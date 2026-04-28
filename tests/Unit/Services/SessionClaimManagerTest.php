<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Infrastructure\SystemClock;
use PhpXdebugMcp\Services\SessionClaimManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionClaimManagerTest extends TestCase
{
    #[Test]
    public function it_grants_a_lease_on_first_claim(): void
    {
        $m = new SessionClaimManager(new SystemClock(), false);
        $lease = $m->claim('s1', 'client-A');
        self::assertNotEmpty($lease);
        self::assertTrue($m->isClaimed('s1'));
    }

    #[Test]
    public function it_returns_the_same_lease_when_the_same_client_re_claims(): void
    {
        $m = new SessionClaimManager(new SystemClock(), false);
        $first = $m->claim('s1', 'client-A');
        $second = $m->claim('s1', 'client-A');
        self::assertSame($first, $second);
    }

    #[Test]
    public function it_rejects_a_second_claim_from_a_different_client(): void
    {
        $m = new SessionClaimManager(new SystemClock(), false);
        $m->claim('s1', 'client-A');
        try {
            $m->claim('s1', 'client-B');
            self::fail('expected SessionAlreadyClaimed');
        } catch (AdapterException $e) {
            self::assertSame(AdapterErrorCode::SessionAlreadyClaimed, $e->errorCode);
        }
    }

    #[Test]
    public function it_releases_a_claim_so_another_client_can_claim(): void
    {
        $m = new SessionClaimManager(new SystemClock(), false);
        $m->claim('s1', 'A');
        $m->release('s1');
        self::assertFalse($m->isClaimed('s1'));
        $m->claim('s1', 'B');
        self::assertTrue($m->isClaimed('s1'));
    }

    #[Test]
    public function it_auto_claims_in_stdio_single_tenant_mode(): void
    {
        $m = new SessionClaimManager(new SystemClock(), true);
        $m->requireClaimed('s1');
        self::assertTrue($m->isClaimed('s1'));
    }
}
