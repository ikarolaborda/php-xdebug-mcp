<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use PhpXdebugMcp\Mcp\SafetyMode;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\PathMapper;
use PhpXdebugMcp\Services\SessionClaimManager;

/**
 * Inspection tools: stack, contexts, variables, properties, source,
 * executable lines, eval (full_control only), set_property (full_control only).
 */
final class InspectionTools
{
    public function __construct(
        private readonly DbgpRuntime $runtime,
        private readonly SessionResolver $resolver,
        private readonly SessionClaimManager $claims,
        private readonly PathMapper $pathMapper,
        private readonly AuditLogger $audit,
        private readonly SafetyMode $safetyMode,
        private readonly int $timeoutMs = 5000,
    ) {
    }

    public function getStack(?string $session_id = null, ?int $depth = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $args = $depth !== null ? ['d' => $depth] : [];
            $r = $this->roundtrip($session, 'stack_get', $args);
            $frames = [];
            foreach ($r['children'] ?? [] as $c) {
                $a = $c['attrs'] ?? [];
                $mapping = $this->pathMapper->fromRemoteUri((string) ($a['filename'] ?? ''));
                $frames[] = [
                    'level' => isset($a['level']) ? (int) $a['level'] : null,
                    'type' => $a['type'] ?? null,
                    'where' => $a['where'] ?? null,
                    'lineno' => isset($a['lineno']) ? (int) $a['lineno'] : null,
                    'filename' => $a['filename'] ?? null,
                    'mapped' => $mapping->toArray(),
                ];
            }
            $this->audit->tool('xdebug_get_stack', $session->adapterId, [], 'ok');

            return ToolResult::ok('Stack with ' . count($frames) . ' frame(s).', ['frames' => $frames], $session);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function getContexts(?string $session_id = null, int $depth = 0): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $r = $this->roundtrip($session, 'context_names', ['d' => $depth]);
            $contexts = [];
            foreach ($r['children'] ?? [] as $c) {
                $a = $c['attrs'] ?? [];
                $contexts[] = [
                    'name' => $a['name'] ?? null,
                    'id' => isset($a['id']) ? (int) $a['id'] : null,
                ];
            }

            return ToolResult::ok(count($contexts) . ' context(s).', ['depth' => $depth, 'contexts' => $contexts], $session);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function getVariables(?string $session_id = null, int $depth = 0, int $context = 0): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $r = $this->roundtrip($session, 'context_get', ['d' => $depth, 'c' => $context]);
            $vars = [];
            foreach ($r['children'] ?? [] as $c) {
                $vars[] = $this->normalizeProperty($c);
            }

            return ToolResult::ok(count($vars) . ' variable(s).', ['depth' => $depth, 'context' => $context, 'variables' => $vars], $session);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function getProperty(string $name, ?string $session_id = null, int $depth = 0, int $context = 0, ?int $page = null, ?int $max_data = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $args = ['n' => $name, 'd' => $depth, 'c' => $context];
            if ($page !== null) {
                $args['p'] = $page;
            }
            if ($max_data !== null) {
                $args['m'] = $max_data;
            }
            $r = $this->roundtrip($session, 'property_get', $args);
            $first = $r['children'][0] ?? null;

            return ToolResult::ok(
                $first === null ? 'No property found.' : 'Property fetched.',
                ['property' => $first === null ? null : $this->normalizeProperty($first)],
                $session,
            );
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function setProperty(string $name, string $value, ?string $type = null, ?string $session_id = null, int $depth = 0, int $context = 0): array
    {
        if (!$this->safetyMode->allowsPropertyMutation()) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::AccessDenied,
                'xdebug_set_property requires safety_mode=full_control.',
            ));
        }
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $args = ['n' => $name, 'd' => $depth, 'c' => $context, 'l' => strlen($value)];
            if ($type !== null) {
                $args['t'] = $type;
            }
            $r = $this->roundtrip($session, 'property_set', $args, $value);

            return ToolResult::ok('Property set.', ['response' => $r['attrs'] ?? []], $session);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function eval(string $code, ?string $session_id = null, ?int $page = null): array
    {
        if (!$this->safetyMode->allowsEval()) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::AccessDenied,
                'xdebug_eval requires safety_mode=full_control.',
            ));
        }
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $args = $page !== null ? ['p' => $page] : [];
            $r = $this->roundtrip($session, 'eval', $args, $code);
            $first = $r['children'][0] ?? null;

            return ToolResult::ok(
                'Expression evaluated.',
                [
                    'value' => $first === null ? null : $this->normalizeProperty($first),
                    'errors' => $r['errors'] ?? [],
                ],
                $session,
            );
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function getSource(string $file_path, ?int $begin = null, ?int $end = null, ?string $session_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $remoteUri = $this->pathMapper->toRemoteUri($file_path)->remoteUri;
            $args = ['f' => $remoteUri];
            if ($begin !== null) {
                $args['b'] = $begin;
            }
            if ($end !== null) {
                $args['e'] = $end;
            }
            $r = $this->roundtrip($session, 'source', $args);
            $body = (string) ($r['attrs']['_text'] ?? '');
            // SimpleXML "value" lives in children when present; the response
            // payload for source is base64 encoded if encoding=base64.
            $rawXml = $r['children'][0]['value'] ?? null;
            if ($rawXml === null && isset($r['attrs']['encoding']) && $r['attrs']['encoding'] === 'base64') {
                $body = base64_decode((string) ($r['attrs']['_value'] ?? ''), true) ?: '';
            }

            return ToolResult::ok(
                'Source retrieved.',
                ['file' => $file_path, 'remote_uri' => $remoteUri, 'lines' => $body],
                $session,
            );
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    public function getExecutableLines(string $file_path, ?string $session_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->requireBreakState($session);
            $remoteUri = $this->pathMapper->toRemoteUri($file_path)->remoteUri;
            $r = $this->roundtrip($session, 'xcmd_get_executable_lines', ['f' => $remoteUri]);
            $lines = [];
            foreach ($r['children'] ?? [] as $c) {
                if (isset($c['attrs']['lineno'])) {
                    $lines[] = (int) $c['attrs']['lineno'];
                }
            }

            return ToolResult::ok(count($lines) . ' executable line(s).', ['lines' => $lines], $session);
        } catch (AdapterException $e) {
            if ($e->errorCode === AdapterErrorCode::FeatureUnsupported) {
                return ToolResult::error($e);
            }

            return ToolResult::error($e);
        }
    }

    public function getTypemap(?string $session_id = null): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $r = $this->roundtrip($session, 'typemap_get', []);
            $maps = [];
            foreach ($r['children'] ?? [] as $c) {
                $maps[] = $c['attrs'] ?? [];
            }

            return ToolResult::ok(count($maps) . ' type entries.', ['types' => $maps], $session);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
    }

    private function roundtrip(DbgpSession $session, string $command, array $args, ?string $rawPayload = null): array
    {
        $captured = null;
        $session->sendCommand(
            $command,
            $args,
            $rawPayload,
            isContinuation: false,
            isBreak: false,
            resolver: function (array $r) use (&$captured): void {
                $captured = $r;
            },
        );
        $this->runtime->tickUntil(static fn (): bool => $captured !== null, $this->timeoutMs);
        if ($captured === null) {
            throw AdapterException::from(
                AdapterErrorCode::Timeout,
                'No response to ' . $command . ' within ' . $this->timeoutMs . 'ms.',
                ['hint' => 'Increase inspection_timeout_ms in config or check the engine for a hung command.'],
            );
        }
        $errors = $captured['errors'] ?? [];
        if ($errors !== []) {
            $first = $errors[0];
            throw AdapterException::from(
                self::mapDbgpError((int) ($first['code'] ?? 0)),
                'Engine error on ' . $command . ': ' . ($first['message'] ?? '') . ' (dbgp ' . (int) ($first['code'] ?? 0) . ')',
                ['dbgp_code' => (int) ($first['code'] ?? 0), 'dbgp_message' => $first['message'] ?? null],
            );
        }

        return $captured;
    }

    private function requireBreakState(DbgpSession $session): void
    {
        $this->claims->requireClaimed($session->adapterId);
        if (!$session->state->allowsInspection()) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidSessionState,
                'Inspection requires the session to be at break or stopping; current: ' . $session->state->value,
                ['session_id' => $session->adapterId, 'state' => $session->state->value],
            );
        }
    }

    private function normalizeProperty(array $node): array
    {
        $attrs = $node['attrs'] ?? [];
        $value = $node['value'] ?? null;
        if (($attrs['encoding'] ?? null) === 'base64' && $value !== null) {
            $decoded = base64_decode((string) $value, true);
            if ($decoded !== false) {
                $value = $decoded;
            }
        }
        $children = [];
        foreach ($node['children'] ?? [] as $c) {
            $children[] = $this->normalizeProperty($c);
        }

        return [
            'name' => $attrs['name'] ?? null,
            'fullname' => $attrs['fullname'] ?? null,
            'type' => $attrs['type'] ?? null,
            'classname' => $attrs['classname'] ?? null,
            'numchildren' => isset($attrs['numchildren']) ? (int) $attrs['numchildren'] : null,
            'has_children' => ($attrs['children'] ?? '0') === '1',
            'value' => $value,
            'children' => $children,
        ];
    }

    private static function mapDbgpError(int $code): AdapterErrorCode
    {
        return match (true) {
            $code === 4 => AdapterErrorCode::FeatureUnsupported,
            $code === 5 => AdapterErrorCode::AsyncNotSupported,
            $code >= 200 && $code <= 206 => AdapterErrorCode::BreakpointValidationFailed,
            $code >= 300 && $code <= 302 => AdapterErrorCode::InvalidSessionState,
            $code === 100 || $code === 101 => AdapterErrorCode::PathMappingFailed,
            $code === 900 => AdapterErrorCode::FeatureUnsupported,
            default => AdapterErrorCode::EngineProtocolError,
        };
    }
}
