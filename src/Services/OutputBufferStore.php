<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\Infrastructure\Clock;

/**
 * Captures stdout/stderr stream chunks produced by the engine when redirect
 * mode (1=copy or 2=redirect) is enabled. Returned to the agent through
 * xdebug_get_session output fields and the dedicated stdout/stderr resources.
 */
final class OutputBufferStore
{
    /** @var array<string, array{stdout:string, stderr:string, last_at:string}> */
    private array $store = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $maxBytesPerStream = 524_288,
    ) {
    }

    public function append(string $sessionId, string $stream, string $data): void
    {
        if (!isset($this->store[$sessionId])) {
            $this->store[$sessionId] = ['stdout' => '', 'stderr' => '', 'last_at' => $this->clock->nowIso8601()];
        }
        $key = $stream === 'stderr' ? 'stderr' : 'stdout';
        $current = $this->store[$sessionId][$key] . $data;
        if (strlen($current) > $this->maxBytesPerStream) {
            $current = substr($current, -$this->maxBytesPerStream);
        }
        $this->store[$sessionId][$key] = $current;
        $this->store[$sessionId]['last_at'] = $this->clock->nowIso8601();
    }

    public function getStdout(string $sessionId): string
    {
        return $this->store[$sessionId]['stdout'] ?? '';
    }

    public function getStderr(string $sessionId): string
    {
        return $this->store[$sessionId]['stderr'] ?? '';
    }

    public function snapshot(string $sessionId): array
    {
        return $this->store[$sessionId] ?? ['stdout' => '', 'stderr' => '', 'last_at' => ''];
    }

    public function dropSession(string $sessionId): void
    {
        unset($this->store[$sessionId]);
    }
}
