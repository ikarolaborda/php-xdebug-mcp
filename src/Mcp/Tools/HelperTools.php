<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\App\Config;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;

/**
 * Convenience helpers that trigger Xdebug debug sessions against the running
 * adapter. They never block on the resulting session — they only spawn the
 * subject. The agent then calls xdebug_wait_for_session.
 *
 * These tools are scaffolded for v1: they spawn a process / fire an HTTP
 * request and return identifiers; they do not stream output. If a more
 * elaborate flow is needed, prefer wiring it from the user side.
 */
final class HelperTools
{
    public function __construct(
        private readonly Config $config,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param string $script absolute path to a PHP script
     * @param array<int, string> $args extra CLI arguments
     */
    public function runCli(string $script, array $args = [], ?string $php_binary = null, array $env_overrides = []): array
    {
        $bin = $php_binary ?? (PHP_BINARY ?: 'php');
        $env = array_replace(getenv(), [
            'XDEBUG_TRIGGER' => '1',
            'XDEBUG_MODE' => 'debug',
            'XDEBUG_CONFIG' => 'client_host=' . $this->config->listenHost . ' client_port=' . $this->config->listenPort,
        ], $env_overrides);

        $cmd = array_merge([$bin], $args !== [] ? $args : [], [$script]);
        $proc = @proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        if (!is_resource($proc)) {
            return ToolResult::error(\PhpXdebugMcp\Domain\Errors\AdapterException::from(
                \PhpXdebugMcp\Domain\Errors\AdapterErrorCode::EngineDisconnected,
                'Failed to spawn PHP CLI process.',
            ));
        }
        @fclose($pipes[0]);
        // Close stdout/stderr pipes — the engine connects back to our DBGp listener.
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $status = proc_get_status($proc);
        $this->audit->tool('php_debug_run_cli', null, ['script' => $script], 'spawned');

        return ToolResult::ok(
            'CLI process spawned; awaiting session.',
            ['pid' => $status['pid'] ?? null, 'cmd' => $cmd],
            nextActions: ['Call xdebug_wait_for_session.'],
        );
    }

    public function httpRequest(string $url, string $method = 'GET', array $headers = [], ?string $body = null, string $cookie_name = 'XDEBUG_SESSION', string $cookie_value = 'mcp'): array
    {
        $cookie = urlencode($cookie_name) . '=' . urlencode($cookie_value);
        $h = $headers + ['Cookie' => $cookie];

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => self::flattenHeaders($h),
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $this->audit->tool('php_debug_http_request', null, ['url' => $url, 'method' => $method], $resp === false ? 'failed' : 'ok');

        return ToolResult::ok(
            $resp === false ? 'HTTP request failed.' : 'HTTP request fired.',
            ['url' => $url, 'method' => $method, 'response_bytes' => $resp === false ? 0 : strlen($resp)],
            nextActions: ['Call xdebug_wait_for_session.'],
        );
    }

    private static function flattenHeaders(array $headers): string
    {
        $out = '';
        foreach ($headers as $k => $v) {
            $out .= $k . ': ' . $v . "\r\n";
        }

        return $out;
    }
}
