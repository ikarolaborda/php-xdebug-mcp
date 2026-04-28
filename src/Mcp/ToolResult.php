<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp;

use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterException;

/**
 * Helpers to produce the canonical tool envelope.
 *
 * { ok, summary, data, warnings, session, next_actions }
 */
final class ToolResult
{
    public static function ok(string $summary, array $data = [], ?DbgpSession $session = null, array $warnings = [], array $nextActions = []): array
    {
        return [
            'ok' => true,
            'summary' => $summary,
            'data' => $data,
            'warnings' => $warnings,
            'session' => self::sessionSnapshot($session),
            'next_actions' => $nextActions,
        ];
    }

    public static function error(AdapterException $e, ?DbgpSession $session = null, array $nextActions = []): array
    {
        return [
            'ok' => false,
            'summary' => $e->getMessage(),
            'data' => [],
            'warnings' => [],
            'session' => self::sessionSnapshot($session),
            'error' => $e->toEnvelope(),
            'next_actions' => $nextActions,
        ];
    }

    public static function sessionSnapshot(?DbgpSession $session): ?array
    {
        if ($session === null) {
            return null;
        }

        return [
            'adapter_id' => $session->adapterId,
            'state' => $session->state->value,
            'reason' => $session->lastReason,
            'peer' => $session->peerAddress,
            'init' => $session->init?->toArray(),
            'features' => [
                'supports_async' => $session->supportsAsync,
                'resolved_breakpoints' => $session->supportsResolved,
                'notify_ok' => $session->supportsNotify,
                'extended_properties' => $session->supportsExtendedProperties,
                'breakpoint_types' => $session->breakpointTypes,
            ],
            'streams' => [
                'stdout_mode' => $session->stdoutMode,
                'stderr_mode' => $session->stderrMode,
            ],
            'warnings' => $session->warningsToArray(),
        ];
    }
}
