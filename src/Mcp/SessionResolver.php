<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp;

use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Services\SessionClaimManager;
use PhpXdebugMcp\Services\SessionRegistry;

/**
 * Encodes the disambiguation rules: if session_id is omitted and there is
 * exactly one claimed session, auto-resolve. Otherwise return a structured
 * SESSION_AMBIGUOUS error so the agent can list and pick.
 */
final class SessionResolver
{
    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly SessionClaimManager $claims,
    ) {
    }

    public function resolve(?string $sessionId): DbgpSession
    {
        if ($sessionId !== null && $sessionId !== '') {
            return $this->registry->get($sessionId);
        }

        $claimed = [];
        foreach ($this->registry->all() as $s) {
            if ($this->claims->isClaimed($s->adapterId)) {
                $claimed[] = $s;
            }
        }

        if (count($claimed) === 1) {
            return $claimed[0];
        }

        if ($claimed === []) {
            $all = $this->registry->all();
            if (count($all) === 1) {
                return array_values($all)[0];
            }
        }

        throw AdapterException::from(
            AdapterErrorCode::SessionAmbiguous,
            'session_id is required when more than one session exists.',
            ['hint' => 'Call xdebug_list_sessions to see ids, then xdebug_claim_session to take control.'],
        );
    }
}
