<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PhpXdebugMcp\Infrastructure\ProcessSpawner;

/**
 * Captures argv/env/cwd of every spawn call. Returns a fixed result so
 * docker helper tests stay deterministic and Docker-free.
 */
final class StubProcessSpawner implements ProcessSpawner
{
    /** @var list<array{argv:list<string>, env:array<string,string>, cwd:?string}> */
    public array $calls = [];

    public function __construct(
        public bool $started = true,
        public ?int $pid = 4242,
        public ?string $error = null,
    ) {
    }

    public function spawnDetached(array $argv, array $env = [], ?string $cwd = null): array
    {
        $this->calls[] = ['argv' => $argv, 'env' => $env, 'cwd' => $cwd];

        return ['pid' => $this->pid, 'started' => $this->started, 'error' => $this->error];
    }
}
