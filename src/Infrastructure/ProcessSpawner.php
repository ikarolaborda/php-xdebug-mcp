<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Infrastructure;

/**
 * Thin seam over proc_open so docker helper tools are deterministically
 * testable. The default implementation actually spawns; tests inject a
 * stub that captures argv + env and returns a fake pid/exit code.
 *
 * Spawned processes are intentionally NOT awaited: helper tools are
 * "spawn-and-go" — the engine connects back to the listener on its own
 * schedule, and the agent picks the session up via xdebug_wait_for_session.
 */
interface ProcessSpawner
{
    /**
     * @param list<string>          $argv  argv form (no shell), first element is the binary
     * @param array<string, string> $env   process environment to apply (existing env is inherited)
     * @param string|null           $cwd   working directory or null to inherit
     *
     * @return array{pid:?int, started:bool, error:?string}
     */
    public function spawnDetached(array $argv, array $env = [], ?string $cwd = null): array;
}
