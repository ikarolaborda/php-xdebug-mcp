<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Sessions;

/**
 * DBGp session execution states as defined by the protocol. See
 * https://xdebug.org/docs/dbgp section 6.1.
 *
 * Disconnected is an adapter-only state used after socket EOF when no normal
 * stop/detach response was received.
 */
enum SessionState: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Break = 'break';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Disconnected = 'disconnected';

    public function isTerminal(): bool
    {
        return $this === self::Stopped || $this === self::Disconnected;
    }

    public function allowsInspection(): bool
    {
        return $this === self::Break || $this === self::Stopping;
    }

    public function allowsContinuation(): bool
    {
        return $this === self::Starting || $this === self::Break;
    }
}
