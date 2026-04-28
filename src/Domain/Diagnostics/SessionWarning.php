<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Diagnostics;

/**
 * Structured advisory attached to a DebugSession. Surfaced via
 * ToolResult::sessionSnapshot so the agent sees it on every tool result.
 *
 * Warnings are advisory: they never block the session or its tools. The
 * structured shape (rather than freeform strings) lets future UI layers
 * group, sort, and dedupe consistently.
 */
final class SessionWarning
{
    public const CODE_PATH_RULE_MISSING = 'PATH_RULE_MISSING';
    public const CODE_IDENTITY_MAPPING_IN_USE = 'IDENTITY_MAPPING_IN_USE';
    public const CODE_BREAKPOINT_PATH_NOT_COVERED = 'BREAKPOINT_PATH_NOT_COVERED';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = [],
        public readonly ?string $hint = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
            'hint' => $this->hint,
        ];
    }

    public function dedupKey(): string
    {
        return $this->code . ':' . md5(serialize($this->context));
    }
}
