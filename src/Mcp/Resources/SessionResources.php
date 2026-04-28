<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Resources;

use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\BreakpointStore;
use PhpXdebugMcp\Services\EventRecorder;
use PhpXdebugMcp\Services\OutputBufferStore;
use PhpXdebugMcp\Services\PathMapper;
use PhpXdebugMcp\Services\SessionClaimManager;
use PhpXdebugMcp\Services\SessionRegistry;

/**
 * Read-only MCP resources. Each resource handler returns an array, which the
 * SDK auto-wraps as a JSON TextContent response.
 */
final class SessionResources
{
    public function __construct(
        private readonly SessionRegistry $registry,
        private readonly BreakpointStore $breakpoints,
        private readonly EventRecorder $events,
        private readonly OutputBufferStore $output,
        private readonly SessionClaimManager $claims,
        private readonly PathMapper $pathMapper,
    ) {
    }

    public function listSessions(): array
    {
        $list = [];
        foreach ($this->registry->all() as $s) {
            $list[] = ToolResult::sessionSnapshot($s) + ['claimed' => $this->claims->isClaimed($s->adapterId)];
        }

        return ['sessions' => $list];
    }

    public function session(string $sessionId): array
    {
        $s = $this->registry->tryGet($sessionId);
        if ($s === null) {
            return ['error' => 'session not found'];
        }
        $snapshot = ToolResult::sessionSnapshot($s);
        $snapshot['claim'] = $this->claims->claimInfo($sessionId);

        return $snapshot ?? [];
    }

    public function stack(string $sessionId): array
    {
        /*
         * Intentionally lightweight: only exposes cached state plus a hint
         * to call xdebug_get_stack for a fresh fetch. Doing a roundtrip
         * here would re-enter the runtime synchronously while the SDK is
         * in the middle of a resource read.
         */
        $s = $this->registry->tryGet($sessionId);
        if ($s === null) {
            return ['error' => 'session not found'];
        }

        return [
            'session_id' => $sessionId,
            'state' => $s->state->value,
            'note' => 'Call tool xdebug_get_stack for a live read; this resource exposes cached state only.',
        ];
    }

    public function breakpoints(string $sessionId): array
    {
        $bps = $this->breakpoints->forSession($sessionId);
        $out = [];
        $session = $this->registry->tryGet($sessionId);
        foreach ($bps as $bp) {
            $row = $bp->toArray();
            if ($session !== null) {
                $row['engine_id'] = $session->bpBindings[$bp->adapterId] ?? null;
            }
            $out[] = $row;
        }

        return ['session_id' => $sessionId, 'breakpoints' => $out];
    }

    public function events(string $sessionId): array
    {
        $list = [];
        foreach ($this->events->recent($sessionId) as $e) {
            $list[] = $e->toArray();
        }

        return ['session_id' => $sessionId, 'events' => $list];
    }

    public function stdout(string $sessionId): array
    {
        return ['session_id' => $sessionId, 'stream' => 'stdout', 'body' => $this->output->getStdout($sessionId)];
    }

    public function stderr(string $sessionId): array
    {
        return ['session_id' => $sessionId, 'stream' => 'stderr', 'body' => $this->output->getStderr($sessionId)];
    }

    public function source(string $sessionId, string $filepath): array
    {
        $decoded = rawurldecode($filepath);
        $remoteUri = $this->pathMapper->toRemoteUri($decoded)->remoteUri;

        return [
            'session_id' => $sessionId,
            'file' => $decoded,
            'remote_uri' => $remoteUri,
            'note' => 'Use tool xdebug_get_source to retrieve the actual file body.',
        ];
    }
}
