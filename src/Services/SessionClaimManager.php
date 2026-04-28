<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Infrastructure\Clock;
use PhpXdebugMcp\Infrastructure\Ids;

/**
 * Tracks which MCP client has exclusive control of each session. v1 uses a
 * single-process model so the "client id" is a token chosen by the client
 * (or generated implicitly for stdio single-tenant mode).
 */
final class SessionClaimManager
{
    /** @var array<string, array{client:string, lease_id:string, claimed_at:string}> */
    private array $claims = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly bool $autoClaimSingleClient = true,
    ) {
    }

    public function claim(string $adapterId, ?string $clientId = null): string
    {
        if (isset($this->claims[$adapterId])) {
            $existing = $this->claims[$adapterId];
            if ($clientId !== null && $existing['client'] === $clientId) {
                return $existing['lease_id'];
            }

            throw AdapterException::from(
                AdapterErrorCode::SessionAlreadyClaimed,
                'Session already claimed by another client.',
                ['session_id' => $adapterId],
            );
        }

        $client = $clientId ?? ($this->autoClaimSingleClient ? 'stdio' : Ids::shortRandom(6));
        $leaseId = Ids::shortRandom(8);
        $this->claims[$adapterId] = [
            'client' => $client,
            'lease_id' => $leaseId,
            'claimed_at' => $this->clock->nowIso8601(),
        ];

        return $leaseId;
    }

    public function release(string $adapterId): void
    {
        unset($this->claims[$adapterId]);
    }

    public function isClaimed(string $adapterId): bool
    {
        return isset($this->claims[$adapterId]);
    }

    public function claimInfo(string $adapterId): ?array
    {
        return $this->claims[$adapterId] ?? null;
    }

    public function requireClaimed(string $adapterId): void
    {
        if ($this->autoClaimSingleClient && !isset($this->claims[$adapterId])) {
            $this->claim($adapterId, 'stdio');

            return;
        }
        if (!isset($this->claims[$adapterId])) {
            throw AdapterException::from(
                AdapterErrorCode::SessionNotClaimed,
                'Tool requires a claimed session.',
                ['session_id' => $adapterId, 'hint' => 'Call xdebug_claim_session first.'],
            );
        }
    }
}
