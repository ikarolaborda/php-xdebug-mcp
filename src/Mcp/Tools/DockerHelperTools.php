<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\App\Config;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Infrastructure\ProcessSpawner;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;

/**
 * Docker-specific helpers: launch a debug session inside a running compose
 * service / container, or spin up an ephemeral container that connects
 * back to the adapter. Both are spawn-and-go; the agent attaches via
 * xdebug_wait_for_session.
 *
 * Identifier validation is conservative: container, service, network, and
 * user names must match `/^[A-Za-z0-9][A-Za-z0-9_.-]*$/`. argv is built
 * with the proc_open array form so there is no shell expansion.
 */
final class DockerHelperTools
{
    public const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/';

    public function __construct(
        private readonly Config $config,
        private readonly ProcessSpawner $spawner,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<int, string>    $args
     * @param array<string, string> $env_overrides
     */
    public function dockerExec(
        string $container_or_service,
        string $script,
        array $args = [],
        ?string $php_binary = null,
        array $env_overrides = [],
        bool $use_compose = false,
        ?string $working_dir = null,
        ?string $user = null,
    ): array {
        try {
            self::assertIdentifier('container_or_service', $container_or_service);
            if ($user !== null) {
                self::assertIdentifier('user', $user);
            }
            self::assertScriptLooksSafe($script);

            $env = $this->mergeEnv($env_overrides);
            /*
             * Both forms are non-interactive: -T disables TTY allocation
             * on compose, plain `docker exec` doesn't allocate a TTY by
             * default and we don't pass -i, so stdin is left closed for
             * the child.
             */
            $argv = $use_compose
                ? ['docker', 'compose', 'exec', '-T']
                : ['docker', 'exec'];

            foreach ($env as $k => $v) {
                $argv[] = '-e';
                $argv[] = $k . '=' . $v;
            }
            if ($user !== null) {
                $argv[] = '-u';
                $argv[] = $user;
            }
            if ($working_dir !== null && !$use_compose) {
                $argv[] = '-w';
                $argv[] = $working_dir;
            }
            $argv[] = $container_or_service;
            $argv[] = $php_binary ?? 'php';
            $argv[] = $script;
            foreach ($args as $a) {
                $argv[] = (string) $a;
            }

            $result = $this->spawner->spawnDetached($argv, [], null);
            $this->audit->tool('php_debug_docker_exec', null, [
                'container' => $container_or_service,
                'use_compose' => $use_compose,
                'script' => $script,
            ], $result['started'] ? 'spawned' : 'failed');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        if (!$result['started']) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::EngineDisconnected,
                'Failed to spawn docker exec: ' . ($result['error'] ?? 'unknown'),
                ['hint' => 'Is the docker CLI installed and the daemon reachable?'],
            ));
        }

        return ToolResult::ok(
            'docker exec spawned; awaiting session.',
            ['pid' => $result['pid'], 'argv' => $argv],
            nextActions: [
                'Call xdebug_wait_for_session — completion of docker exec does NOT mean the engine has connected yet.',
            ],
        );
    }

    /**
     * @param array<int, string>    $command
     * @param array<string, string> $env_overrides
     * @param array<int, string>    $volumes  in `host:container[:mode]` form
     * @param array<int, string>    $extra_hosts in `name:ip` form
     */
    public function dockerRun(
        string $image,
        array $command = [],
        array $env_overrides = [],
        array $volumes = [],
        array $extra_hosts = [],
        ?string $working_dir = null,
        ?string $network = null,
    ): array {
        try {
            if ($image === '') {
                throw AdapterException::from(AdapterErrorCode::InvalidArgument, 'image must be non-empty.');
            }
            if ($network !== null) {
                self::assertIdentifier('network', $network);
            }
            $env = $this->mergeEnv($env_overrides);
            $hosts = $extra_hosts === [] ? $this->config->dockerExtraHosts : $extra_hosts;

            $argv = ['docker', 'run', '--rm'];
            foreach ($env as $k => $v) {
                $argv[] = '-e';
                $argv[] = $k . '=' . $v;
            }
            foreach ($volumes as $vol) {
                self::assertVolume($vol);
                $argv[] = '-v';
                $argv[] = $vol;
            }
            foreach ($hosts as $h) {
                self::assertExtraHost($h);
                $argv[] = '--add-host';
                $argv[] = $h;
            }
            if ($working_dir !== null) {
                $argv[] = '-w';
                $argv[] = $working_dir;
            }
            if ($network !== null) {
                $argv[] = '--network';
                $argv[] = $network;
            }
            $argv[] = $image;
            foreach ($command as $part) {
                $argv[] = (string) $part;
            }

            $result = $this->spawner->spawnDetached($argv, [], null);
            $this->audit->tool('php_debug_docker_run', null, [
                'image' => $image,
                'network' => $network,
                'extra_hosts_count' => count($hosts),
            ], $result['started'] ? 'spawned' : 'failed');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        if (!$result['started']) {
            return ToolResult::error(AdapterException::from(
                AdapterErrorCode::EngineDisconnected,
                'Failed to spawn docker run: ' . ($result['error'] ?? 'unknown'),
                ['hint' => 'Is the docker CLI installed and the daemon reachable?'],
            ));
        }

        return ToolResult::ok(
            'docker run spawned; awaiting session.',
            ['pid' => $result['pid'], 'argv' => $argv],
            nextActions: [
                'Call xdebug_wait_for_session — the engine connects back asynchronously.',
            ],
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function mergeEnv(array $overrides): array
    {
        $defaults = [
            'XDEBUG_MODE' => 'debug',
            'XDEBUG_TRIGGER' => '1',
            'XDEBUG_CONFIG' => 'client_host=' . $this->dockerListenerHost() . ' client_port=' . $this->config->listenPort,
        ];
        foreach ($overrides as $k => $v) {
            if (!is_string($k) || preg_match('/^[A-Z_][A-Z0-9_]*$/', $k) !== 1) {
                throw AdapterException::from(
                    AdapterErrorCode::InvalidArgument,
                    'env_overrides keys must be POSIX-style upper-case identifiers; got: ' . (string) $k,
                );
            }
            $defaults[$k] = (string) $v;
        }

        return $defaults;
    }

    private function dockerListenerHost(): string
    {
        if ($this->config->listenHost === '127.0.0.1' || $this->config->listenHost === '::1') {
            return 'host.docker.internal';
        }

        return $this->config->listenHost;
    }

    private static function assertIdentifier(string $field, string $value): void
    {
        if (preg_match(self::ID_PATTERN, $value) !== 1 || strlen($value) > 200) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidArgument,
                $field . ' must match ' . self::ID_PATTERN . ' (got: ' . $value . ').',
                ['hint' => 'Reject shell metacharacters; this is a hard safety check.'],
            );
        }
    }

    private static function assertScriptLooksSafe(string $script): void
    {
        if ($script === '' || str_contains($script, "\x00")) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidArgument,
                'script must be a non-empty string without NUL bytes.',
            );
        }
    }

    private static function assertVolume(string $vol): void
    {
        if (preg_match('/^[^\s:]+:[^\s:]+(:[a-zA-Z]+)?$/', $vol) !== 1) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidArgument,
                'volume must be host:container[:mode] (got: ' . $vol . ').',
            );
        }
    }

    private static function assertExtraHost(string $h): void
    {
        if (preg_match('/^[A-Za-z0-9._-]+:[A-Za-z0-9.\-_]+$/', $h) !== 1) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidArgument,
                'extra_host must be name:ip (got: ' . $h . ').',
            );
        }
    }
}
