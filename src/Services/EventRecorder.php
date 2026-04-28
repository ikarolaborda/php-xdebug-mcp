<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\Domain\Events\EventKind;
use PhpXdebugMcp\Domain\Events\SessionEvent;
use PhpXdebugMcp\Infrastructure\Clock;

/**
 * Per-session ring buffer of recent events. Used by the events resource and
 * for surfaces that want to summarise what happened around the most recent
 * stop/break.
 */
final class EventRecorder
{
    /** @var array<string, list<SessionEvent>> */
    private array $events = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $perSessionLimit = 256,
    ) {
    }

    public function record(string $sessionId, EventKind $kind, array $data = []): SessionEvent
    {
        $event = new SessionEvent($kind, $sessionId, $this->clock->nowIso8601(), $data);
        $this->events[$sessionId][] = $event;
        if (count($this->events[$sessionId]) > $this->perSessionLimit) {
            array_shift($this->events[$sessionId]);
        }

        return $event;
    }

    /** @return list<SessionEvent> */
    public function recent(string $sessionId, int $limit = 50): array
    {
        $list = $this->events[$sessionId] ?? [];
        $offset = max(0, count($list) - $limit);

        return array_values(array_slice($list, $offset));
    }

    public function dropSession(string $sessionId): void
    {
        unset($this->events[$sessionId]);
    }
}
