<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;

final class SessionRegistry
{
    /** @var array<string, DbgpSession> */
    private array $sessions = [];

    public function add(DbgpSession $session): void
    {
        $this->sessions[$session->adapterId] = $session;
    }

    public function remove(string $adapterId): void
    {
        unset($this->sessions[$adapterId]);
    }

    public function get(string $adapterId): DbgpSession
    {
        if (!isset($this->sessions[$adapterId])) {
            throw AdapterException::from(
                AdapterErrorCode::SessionNotFound,
                'No session with adapter id ' . $adapterId,
                ['session_id' => $adapterId],
            );
        }

        return $this->sessions[$adapterId];
    }

    public function tryGet(string $adapterId): ?DbgpSession
    {
        return $this->sessions[$adapterId] ?? null;
    }

    /** @return array<string, DbgpSession> */
    public function all(): array
    {
        return $this->sessions;
    }

    public function count(): int
    {
        return count($this->sessions);
    }
}
