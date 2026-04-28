<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Infrastructure;

final class RealProcessSpawner implements ProcessSpawner
{
    public function spawnDetached(array $argv, array $env = [], ?string $cwd = null): array
    {
        if ($argv === []) {
            return ['pid' => null, 'started' => false, 'error' => 'empty argv'];
        }

        $mergedEnv = array_replace((array) getenv(), $env);

        $proc = @proc_open($argv, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd, $mergedEnv);

        if (!is_resource($proc)) {
            return ['pid' => null, 'started' => false, 'error' => 'proc_open returned false'];
        }

        @fclose($pipes[0]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $status = proc_get_status($proc);

        return ['pid' => $status['pid'] ?? null, 'started' => true, 'error' => null];
    }
}
