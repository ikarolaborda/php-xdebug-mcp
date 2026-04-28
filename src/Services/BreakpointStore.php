<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\Domain\Breakpoints\BreakpointDefinition;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointScope;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;

/**
 * Adapter-level breakpoint registry.
 *
 * Persistent breakpoints are kept indefinitely and replayed into every new
 * session whose path mapping resolves the file. Session-scoped breakpoints
 * are tagged with the owning session id and removed when the session ends
 * or when xdebug_run_to_cursor's temp pin completes.
 */
final class BreakpointStore
{
    /** @var array<string, BreakpointDefinition> */
    private array $persistent = [];

    /** @var array<string, array<string, BreakpointDefinition>> sessionId => [adapterId => def] */
    private array $sessionScoped = [];

    public function addPersistent(BreakpointDefinition $def): void
    {
        if ($def->scope !== BreakpointScope::Persistent) {
            throw new \LogicException('Definition is not persistent.');
        }
        $this->persistent[$def->adapterId] = $def;
    }

    public function addSessionScoped(string $sessionId, BreakpointDefinition $def): void
    {
        if ($def->scope !== BreakpointScope::Session) {
            throw new \LogicException('Definition is not session-scoped.');
        }
        $this->sessionScoped[$sessionId][$def->adapterId] = $def;
    }

    public function update(string $adapterId, BreakpointDefinition $def): void
    {
        if (isset($this->persistent[$adapterId])) {
            $this->persistent[$adapterId] = $def;

            return;
        }
        foreach ($this->sessionScoped as $sid => $list) {
            if (isset($list[$adapterId])) {
                $this->sessionScoped[$sid][$adapterId] = $def;

                return;
            }
        }
        throw AdapterException::from(
            AdapterErrorCode::BreakpointValidationFailed,
            'Cannot update unknown breakpoint ' . $adapterId,
        );
    }

    public function remove(string $adapterId): BreakpointDefinition
    {
        if (isset($this->persistent[$adapterId])) {
            $def = $this->persistent[$adapterId];
            unset($this->persistent[$adapterId]);

            return $def;
        }
        foreach ($this->sessionScoped as $sid => $list) {
            if (isset($list[$adapterId])) {
                $def = $list[$adapterId];
                unset($this->sessionScoped[$sid][$adapterId]);

                return $def;
            }
        }
        throw AdapterException::from(
            AdapterErrorCode::BreakpointValidationFailed,
            'Cannot remove unknown breakpoint ' . $adapterId,
        );
    }

    public function get(string $adapterId): BreakpointDefinition
    {
        if (isset($this->persistent[$adapterId])) {
            return $this->persistent[$adapterId];
        }
        foreach ($this->sessionScoped as $list) {
            if (isset($list[$adapterId])) {
                return $list[$adapterId];
            }
        }
        throw AdapterException::from(
            AdapterErrorCode::BreakpointValidationFailed,
            'Unknown breakpoint ' . $adapterId,
        );
    }

    public function tryGet(string $adapterId): ?BreakpointDefinition
    {
        if (isset($this->persistent[$adapterId])) {
            return $this->persistent[$adapterId];
        }
        foreach ($this->sessionScoped as $list) {
            if (isset($list[$adapterId])) {
                return $list[$adapterId];
            }
        }

        return null;
    }

    /** @return array<string, BreakpointDefinition> */
    public function persistent(): array
    {
        return $this->persistent;
    }

    /** @return array<string, BreakpointDefinition> */
    public function forSession(string $sessionId): array
    {
        $session = $this->sessionScoped[$sessionId] ?? [];

        return $this->persistent + $session;
    }

    public function dropSession(string $sessionId): void
    {
        unset($this->sessionScoped[$sessionId]);
    }
}
