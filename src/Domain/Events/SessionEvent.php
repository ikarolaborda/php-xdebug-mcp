<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Events;

final readonly class SessionEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public EventKind $kind,
        public string $sessionId,
        public string $occurredAt,
        public array $data = [],
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
