<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use Psr\Log\LoggerInterface;

/**
 * Wraps the configured PSR-3 logger to emit structured audit lines for MCP
 * tool invocations and DBGp command summaries. Sensitive bodies (eval code,
 * stdin payloads) are length-summarised rather than logged verbatim.
 */
final class AuditLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function tool(string $tool, ?string $sessionId, array $args, string $outcome, array $extra = []): void
    {
        $this->logger->info('mcp.tool', [
            'tool' => $tool,
            'session' => $sessionId,
            'args' => self::redact($args),
            'outcome' => $outcome,
        ] + $extra);
    }

    public function command(string $sessionId, string $command, int $txid, string $outcome): void
    {
        $this->logger->info('dbgp.command', [
            'session' => $sessionId,
            'txid' => $txid,
            'command' => $command,
            'outcome' => $outcome,
        ]);
    }

    public function notification(string $sessionId, string $name, array $data): void
    {
        $this->logger->info('dbgp.notify', [
            'session' => $sessionId,
            'name' => $name,
            'data' => self::redact($data),
        ]);
    }

    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, ['code', 'expression', 'value', 'data', 'stdin'], true) && is_string($v)) {
                $out[$k] = '<' . strlen($v) . ' bytes>';
                continue;
            }
            $out[$k] = is_array($v) ? self::redact($v) : $v;
        }

        return $out;
    }
}
