<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Events;

final class SessionEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly EventKind $kind,
        public readonly string $sessionId,
        public readonly string $occurredAt,
        public readonly array $data = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'session_id' => $this->sessionId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
        ];
    }
}
