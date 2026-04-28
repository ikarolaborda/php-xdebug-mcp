<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Mcp\SafetyMode;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\OutputBufferStore;
use PhpXdebugMcp\Services\SessionClaimManager;

/**
 * stdout/stderr capture configuration and stdin push (full_control only).
 */
final class IoTools
{
    public function __construct(
        private readonly DbgpRuntime $runtime,
        private readonly SessionResolver $resolver,
        private readonly SessionClaimManager $claims,
        private readonly OutputBufferStore $output,
        private readonly AuditLogger $audit,
        private readonly SafetyMode $safetyMode,
    ) {
    }

    /**
     * @param string $stdout one of '0' (disable), '1' (copy), '2' (redirect)
     * @param string $stderr one of '0' (disable), '1' (copy), '2' (redirect)
     */
    public function configureOutput(string $stdout = '1', string $stderr = '1', ?string $session_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->claims->requireClaimed($session->adapterId);
            $this->setStream($session, 'stdout', $stdout);
            $this->setStream($session, 'stderr', $stderr);
            $session->stdoutMode = $stdout;
            $session->stderrMode = $stderr;
            $this->audit->tool('xdebug_configure_output', $session->adapterId, ['stdout' => $stdout, 'stderr' => $stderr], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok(
            'Output capture configured.',
            ['stdout_mode' => $stdout, 'stderr_mode' => $stderr],
            $session,
        );
    }

    public function sendStdin(string $data, ?string $session_id = null): array
    {
        if (!$this->safetyMode->allowsStdin()) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::AccessDenied,
                'xdebug_send_stdin requires safety_mode=full_control.',
            ));
        }
        try {
            $session = $this->resolver->resolve($session_id);
            $this->claims->requireClaimed($session->adapterId);
            $captured = null;
            $session->sendCommand(
                'stdin',
                ['c' => '1'],
                null,
                isContinuation: false,
                isBreak: false,
                resolver: function (array $r) use (&$captured): void {
                    $captured = $r;
                },
            );
            $this->runtime->tickUntil(function () use (&$captured): bool { return $captured !== null; }, 1500);
            $session->sendCommand(
                'stdin',
                [],
                $data,
                isContinuation: false,
                isBreak: false,
                resolver: static function () {},
            );
            $this->runtime->tickUntil(static fn (): bool => $session->inFlight() === null, 1500);
            $this->audit->tool('xdebug_send_stdin', $session->adapterId, ['len' => strlen($data)], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok('Stdin pushed (' . strlen($data) . ' bytes).', [], $session);
    }

    private function setStream(DbgpSession $session, string $stream, string $mode): void
    {
        $captured = null;
        $session->sendCommand(
            $stream,
            ['c' => $mode],
            null,
            isContinuation: false,
            isBreak: false,
            resolver: function (array $r) use (&$captured): void {
                $captured = $r;
            },
        );
        $this->runtime->tickUntil(function () use (&$captured): bool { return $captured !== null; }, 1500);
        if ($captured === null) {
            throw AdapterException::from(AdapterErrorCode::Timeout, 'Timeout configuring ' . $stream);
        }
    }
}
