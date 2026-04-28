<?php

declare(strict_types=1);

namespace PhpXdebugMcp\App;

use PhpXdebugMcp\Domain\Paths\PathMappingRule;
use PhpXdebugMcp\Mcp\SafetyMode;

/**
 * Immutable adapter configuration.
 *
 * Loaded from config/php-xdebug-mcp.php (file array) and overlaid with
 * environment variables. Stdio mode never writes to stdout — log_path
 * defaults to a per-process file under storage/.
 */
final class Config
{
    /**
     * @param list<PathMappingRule> $pathRules
     * @param list<string>          $allowedClientIps
     * @param list<string>          $workspaceRoots
     */
    public function __construct(
        public readonly string $serverName,
        public readonly string $serverVersion,
        public readonly string $listenHost,
        public readonly int $listenPort,
        public readonly SafetyMode $safetyMode,
        public readonly bool $allowStop,
        public readonly bool $allowDetach,
        public readonly array $pathRules,
        public readonly array $allowedClientIps,
        public readonly array $workspaceRoots,
        public readonly bool $allowReadsOutsideWorkspace,
        public readonly string $logPath,
        public readonly string $logLevel,
        public readonly int $continuationTimeoutMs,
        public readonly int $inspectionTimeoutMs,
        public readonly int $defaultMaxChildren,
        public readonly int $defaultMaxData,
        public readonly int $defaultMaxDepth,
        public readonly bool $enableHttpTransport,
        public readonly string $httpHost,
        public readonly int $httpPort,
        public readonly string $httpPathPrefix,
        public readonly bool $dockerHelpersEnabled,
        public readonly array $dockerExtraHosts,
    ) {
    }

    public static function fromArray(array $cfg): self
    {
        $rules = [];
        foreach ($cfg['path_rules'] ?? [] as $r) {
            $rules[] = new PathMappingRule(
                localRoot: (string) $r['local'],
                remoteRoot: (string) $r['remote'],
                exactFiles: (array) ($r['exact'] ?? []),
                precedence: (int) ($r['precedence'] ?? 100),
                label: (string) ($r['label'] ?? ''),
            );
        }

        return new self(
            serverName: (string) ($cfg['server_name'] ?? 'php-xdebug-mcp'),
            serverVersion: (string) ($cfg['server_version'] ?? '0.1.0'),
            listenHost: (string) ($cfg['listen_host'] ?? '127.0.0.1'),
            listenPort: (int) ($cfg['listen_port'] ?? 9003),
            safetyMode: SafetyMode::from((string) ($cfg['safety_mode'] ?? 'control')),
            allowStop: (bool) ($cfg['allow_stop'] ?? true),
            allowDetach: (bool) ($cfg['allow_detach'] ?? true),
            pathRules: $rules,
            allowedClientIps: array_values((array) ($cfg['allowed_client_ips'] ?? [])),
            workspaceRoots: array_values((array) ($cfg['workspace_roots'] ?? [getcwd() ?: '/'])),
            allowReadsOutsideWorkspace: (bool) ($cfg['allow_reads_outside_workspace'] ?? false),
            logPath: (string) ($cfg['log_path'] ?? 'php://stderr'),
            logLevel: (string) ($cfg['log_level'] ?? 'info'),
            continuationTimeoutMs: (int) ($cfg['continuation_timeout_ms'] ?? 30000),
            inspectionTimeoutMs: (int) ($cfg['inspection_timeout_ms'] ?? 5000),
            defaultMaxChildren: (int) ($cfg['default_max_children'] ?? 100),
            defaultMaxData: (int) ($cfg['default_max_data'] ?? 4096),
            defaultMaxDepth: (int) ($cfg['default_max_depth'] ?? 3),
            enableHttpTransport: (bool) ($cfg['http_enabled'] ?? false),
            httpHost: (string) ($cfg['http_host'] ?? '127.0.0.1'),
            httpPort: (int) ($cfg['http_port'] ?? 9333),
            httpPathPrefix: (string) ($cfg['http_path_prefix'] ?? 'mcp'),
            dockerHelpersEnabled: (bool) ($cfg['docker_helpers_enabled'] ?? false),
            dockerExtraHosts: array_values((array) ($cfg['docker_extra_hosts'] ?? ['host.docker.internal:host-gateway'])),
        );
    }

    public static function load(?string $path = null): self
    {
        $path ??= getenv('PHP_XDEBUG_MCP_CONFIG') ?: __DIR__ . '/../../config/php-xdebug-mcp.php';
        $cfg = is_file($path) ? require $path : [];

        if (!is_array($cfg)) {
            $cfg = [];
        }

        $env = self::envOverrides();

        return self::fromArray(array_replace_recursive($cfg, $env));
    }

    /** @return array<string, mixed> */
    private static function envOverrides(): array
    {
        $out = [];
        $map = [
            'XDEBUG_MCP_LISTEN_HOST' => 'listen_host',
            'XDEBUG_MCP_LISTEN_PORT' => 'listen_port',
            'XDEBUG_MCP_SAFETY_MODE' => 'safety_mode',
            'XDEBUG_MCP_LOG_PATH' => 'log_path',
            'XDEBUG_MCP_LOG_LEVEL' => 'log_level',
            'XDEBUG_MCP_HTTP_ENABLED' => 'http_enabled',
            'XDEBUG_MCP_HTTP_HOST' => 'http_host',
            'XDEBUG_MCP_HTTP_PORT' => 'http_port',
            'XDEBUG_MCP_DOCKER_HELPERS_ENABLED' => 'docker_helpers_enabled',
        ];

        foreach ($map as $envName => $cfgKey) {
            $v = getenv($envName);
            if ($v === false || $v === '') {
                continue;
            }
            if (in_array($cfgKey, ['listen_port', 'http_port'], true)) {
                $out[$cfgKey] = (int) $v;
                continue;
            }
            if (in_array($cfgKey, ['http_enabled', 'docker_helpers_enabled'], true)) {
                $out[$cfgKey] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            $out[$cfgKey] = $v;
        }

        return $out;
    }
}
