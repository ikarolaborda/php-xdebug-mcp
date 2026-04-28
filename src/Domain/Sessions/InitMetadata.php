<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Sessions;

/**
 * Captured attributes from the engine's <init> packet.
 */
final class InitMetadata
{
    public function __construct(
        public readonly string $appId,
        public readonly ?string $ideKey,
        public readonly ?string $sessionCookie,
        public readonly ?string $thread,
        public readonly ?string $parent,
        public readonly string $language,
        public readonly string $protocolVersion,
        public readonly string $fileUri,
    ) {
    }

    public function toArray(): array
    {
        return [
            'appid' => $this->appId,
            'idekey' => $this->ideKey,
            'session' => $this->sessionCookie,
            'thread' => $this->thread,
            'parent' => $this->parent,
            'language' => $this->language,
            'protocol_version' => $this->protocolVersion,
            'fileuri' => $this->fileUri,
        ];
    }
}
