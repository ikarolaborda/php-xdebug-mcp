<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\App\Config;
use PhpXdebugMcp\Dbgp\DbgpListener;
use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\SessionClaimManager;
use PhpXdebugMcp\Services\SessionRegistry;

/**
 * Lifecycle tools: status, list, wait, get, claim, release.
 *
 * These are always exposed regardless of safety mode (observe-or-better).
 */
final class SessionTools
{
    public function __construct(
        private readonly DbgpRuntime $runtime,
        private readonly DbgpListener $listener,
        private readonly SessionRegistry $registry,
        private readonly SessionClaimManager $claims,
        private readonly SessionResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * Return a snapshot of the listener and the count of currently attached
     * sessions.
     */
    public function serverStatus(): array
    {
        $sessions = [];
        foreach ($this->registry->all() as $s) {
            $sessions[] = [
                'adapter_id' => $s->adapterId,
                'state' => $s->state->value,
                'peer' => $s->peerAddress,
                'claimed' => $this->claims->isClaimed($s->adapterId),
            ];
        }
        $this->audit->tool('xdebug_server_status', null, [], 'ok');

        return ToolResult::ok(
            sprintf('Listener at %s; %d session(s) attached.', $this->listener->getAddress(), count($sessions)),
            [
                'listener' => $this->listener->getAddress(),
                'safety_mode' => $this->config->safetyMode->value,
                'allow_stop' => $this->config->allowStop,
                'allow_detach' => $this->config->allowDetach,
                'sessions' => $sessions,
            ],
            nextActions: $sessions === []
                ? ['Call xdebug_wait_for_session to block until Xdebug connects.']
                : ['Call xdebug_claim_session with one of the listed adapter_ids.'],
        );
    }

    public function listSessions(): array
    {
        $list = [];
        foreach ($this->registry->all() as $s) {
            $list[] = ToolResult::sessionSnapshot($s);
        }

        return ToolResult::ok(
            sprintf('%d session(s).', count($list)),
            ['sessions' => $list],
        );
    }

    /**
     * Wait up to timeout_ms milliseconds for a new session to attach (or for
     * any session to reach the requested state). Returns immediately if a
     * matching session already exists.
     */
    public function waitForSession(int $timeout_ms = 30000, ?string $expected_state = null): array
    {
        $beforeIds = array_keys($this->registry->all());
        $deadline = $timeout_ms;

        $hit = $this->runtime->tickUntil(
            function () use ($beforeIds, $expected_state): bool {
                $now = $this->registry->all();
                if ($expected_state !== null) {
                    foreach ($now as $s) {
                        if ($s->state->value === $expected_state) {
                            return true;
                        }
                    }

                    return false;
                }
                foreach (array_keys($now) as $id) {
                    if (!in_array($id, $beforeIds, true)) {
                        return true;
                    }
                }

                return false;
            },
            $deadline,
        );

        $sessions = [];
        foreach ($this->registry->all() as $s) {
            $sessions[] = ToolResult::sessionSnapshot($s);
        }

        return ToolResult::ok(
            $hit ? 'Session detected.' : 'Timeout reached without a new session.',
            ['hit' => $hit, 'sessions' => $sessions],
            nextActions: $hit ? ['Call xdebug_claim_session to take control.'] : ['Trigger a debug request and call xdebug_wait_for_session again.'],
        );
    }

    public function getSession(?string $session_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok('Session ' . $session->adapterId, [], $session);
    }

    public function claimSession(?string $session_id = null, ?string $client_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $lease = $this->claims->claim($session->adapterId, $client_id);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
        $this->audit->tool('xdebug_claim_session', $session->adapterId, ['client_id' => $client_id], 'ok');

        return ToolResult::ok(
            'Claimed session ' . $session->adapterId,
            ['lease_id' => $lease],
            $session,
            nextActions: ['Set breakpoints with xdebug_set_breakpoint, then call xdebug_continue.'],
        );
    }

    public function releaseSession(?string $session_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->claims->release($session->adapterId);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
        $this->audit->tool('xdebug_release_session', $session->adapterId, [], 'ok');

        return ToolResult::ok('Released session ' . $session->adapterId, [], $session);
    }
}
