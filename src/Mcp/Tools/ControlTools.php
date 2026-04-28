<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\App\Config;
use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\SessionClaimManager;

/**
 * Control surface: continuation, stepping, break, stop, detach, wait_for_state.
 *
 * Continuation commands occupy the session's continuation slot. The runtime
 * pump processes the response when the engine eventually breaks/stops; if the
 * tool's deadline elapses first we return a structured "still running" result
 * keeping the session alive.
 */
final class ControlTools
{
    public function __construct(
        private readonly DbgpRuntime $runtime,
        private readonly SessionResolver $resolver,
        private readonly SessionClaimManager $claims,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {
    }

    public function continue(?string $session_id = null, ?int $timeout_ms = null): array
    {
        return $this->stepLike('run', $session_id, $timeout_ms, 'xdebug_continue');
    }

    public function stepInto(?string $session_id = null, ?int $timeout_ms = null): array
    {
        return $this->stepLike('step_into', $session_id, $timeout_ms, 'xdebug_step_into');
    }

    public function stepOver(?string $session_id = null, ?int $timeout_ms = null): array
    {
        return $this->stepLike('step_over', $session_id, $timeout_ms, 'xdebug_step_over');
    }

    public function stepOut(?string $session_id = null, ?int $timeout_ms = null): array
    {
        return $this->stepLike('step_out', $session_id, $timeout_ms, 'xdebug_step_out');
    }

    public function breakExecution(?string $session_id = null, int $timeout_ms = 5000): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->claims->requireClaimed($session->adapterId);
            if (!$session->supportsAsync) {
                throw AdapterException::from(
                    AdapterErrorCode::AsyncNotSupported,
                    'Engine did not advertise supports_async=1; break is unavailable.',
                    ['session_id' => $session->adapterId],
                );
            }
            $captured = null;
            $session->sendCommand(
                'break',
                [],
                null,
                isContinuation: false,
                isBreak: true,
                resolver: function (array $r) use (&$captured): void {
                    $captured = $r;
                },
            );
            $this->runtime->tickUntil(static fn (): bool => $captured !== null, $timeout_ms);
            $this->audit->tool('xdebug_break_execution', $session->adapterId, [], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok(
            $captured === null ? 'break command timed out without acknowledgement' : 'break acknowledged',
            ['response' => $captured],
            $session,
        );
    }

    public function stop(?string $session_id = null): array
    {
        if (!$this->config->allowStop) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::AccessDenied,
                'xdebug_stop is disabled by configuration (allow_stop=false).',
            ));
        }

        return $this->terminateLike('stop', $session_id, 'xdebug_stop');
    }

    public function detach(?string $session_id = null): array
    {
        if (!$this->config->allowDetach) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::AccessDenied,
                'xdebug_detach is disabled by configuration (allow_detach=false).',
            ));
        }

        return $this->terminateLike('detach', $session_id, 'xdebug_detach');
    }

    public function waitForState(?string $session_id = null, string $expected_state = 'break', int $timeout_ms = 30000): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        $hit = $this->runtime->tickUntil(
            static fn (): bool => $session->state->value === $expected_state,
            $timeout_ms,
        );

        return ToolResult::ok(
            $hit ? 'Session reached state ' . $expected_state : 'Timeout reached; session still in ' . $session->state->value,
            ['hit' => $hit, 'expected' => $expected_state],
            $session,
        );
    }

    private function stepLike(string $command, ?string $sessionId, ?int $timeoutMs, string $toolName): array
    {
        try {
            $session = $this->resolver->resolve($sessionId);
            $this->claims->requireClaimed($session->adapterId);
            if (!$session->state->allowsContinuation()) {
                throw AdapterException::from(
                    AdapterErrorCode::InvalidSessionState,
                    'Session is not in a state that accepts a continuation: ' . $session->state->value,
                    ['session_id' => $session->adapterId, 'state' => $session->state->value],
                );
            }
            $captured = null;
            $session->sendCommand(
                $command,
                [],
                null,
                isContinuation: true,
                isBreak: false,
                resolver: function (array $r) use (&$captured): void {
                    $captured = $r;
                },
            );
            $deadline = $timeoutMs ?? $this->config->continuationTimeoutMs;
            $this->runtime->tickUntil(static fn (): bool => $captured !== null, $deadline);
            $this->audit->tool($toolName, $session->adapterId, ['timeout_ms' => $deadline], $captured === null ? 'still_running' : 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        if ($captured === null) {
            return ToolResult::ok(
                'Continuation still running after deadline; session kept alive.',
                [
                    'still_running' => true,
                    'state' => $session->state->value,
                    'interruptible' => $session->supportsAsync,
                    'recommended_actions' => $session->supportsAsync
                        ? ['xdebug_wait_for_state', 'xdebug_break_execution']
                        : ['xdebug_wait_for_state'],
                ],
                $session,
                warnings: ['Tool deadline elapsed; the engine has not broken/stopped yet.'],
                nextActions: ['Call xdebug_wait_for_state to keep waiting' . ($session->supportsAsync ? ', or xdebug_break_execution to interrupt.' : '.')],
            );
        }

        return ToolResult::ok(
            sprintf('Session reached %s after %s.', $session->state->value, $command),
            ['response' => $captured],
            $session,
        );
    }

    private function terminateLike(string $command, ?string $sessionId, string $toolName): array
    {
        try {
            $session = $this->resolver->resolve($sessionId);
            $this->claims->requireClaimed($session->adapterId);
            $captured = null;
            try {
                $session->sendCommand(
                    $command,
                    [],
                    null,
                    isContinuation: true,
                    isBreak: false,
                    resolver: function (array $r) use (&$captured): void {
                        $captured = $r;
                    },
                );
            } catch (AdapterException $e) {
                // sending stop on a half-closed socket may immediately fail; treat as success.
                if ($e->errorCode === AdapterErrorCode::EngineDisconnected) {
                    $captured = ['status' => SessionState::Stopped->value, 'reason' => 'eof'];
                } else {
                    throw $e;
                }
            }
            $this->runtime->tickUntil(
                static fn (): bool => $captured !== null || $session->state === SessionState::Disconnected,
                5000,
            );
            $this->audit->tool($toolName, $session->adapterId, [], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok(
            $command . ' acknowledged',
            ['response' => $captured],
            $session,
        );
    }
}
