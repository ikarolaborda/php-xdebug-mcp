<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Sessions;

/**
 * Captured attributes from the engine's <init> packet.
 */
final readonly class InitMetadata
{
    public function __construct(
        public string $appId,
        public ?string $ideKey,
        public ?string $sessionCookie,
        public ?string $thread,
        public ?string $parent,
        public string $language,
        public string $protocolVersion,
        public string $fileUri,
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
