<?php

declare(strict_types=1);

/**
 * php-xdebug-mcp adapter configuration.
 *
 * Local-first defaults. Environment variables override values here. See
 * docs/safety-modes.md and docs/path-mapping.md for the full reference.
 */
return [
    'server_name' => 'php-xdebug-mcp',
    'server_version' => '0.1.0',

    'listen_host' => '127.0.0.1',
    'listen_port' => 9003,

    'safety_mode' => 'control',
    'allow_stop' => true,
    'allow_detach' => true,

    'path_rules' => [
        // [
        //     'label' => 'docker app',
        //     'local' => '/home/me/projects/app',
        //     'remote' => '/var/www/html',
        //     'exact' => [],
        //     'precedence' => 100,
        // ],
    ],

    'allowed_client_ips' => [],
    'workspace_roots' => [getcwd() ?: '/'],
    'allow_reads_outside_workspace' => false,

    'log_path' => 'php://stderr',
    'log_level' => 'info',

    'continuation_timeout_ms' => 30000,
    'inspection_timeout_ms' => 5000,

    'default_max_children' => 100,
    'default_max_data' => 4096,
    'default_max_depth' => 3,

    'http_enabled' => false,
    'http_host' => '127.0.0.1',
    'http_port' => 9333,
    'http_path_prefix' => 'mcp',

    // Docker helpers: php_debug_docker_exec / php_debug_docker_run.
    // Default off because they shell out to the docker CLI on the host.
    'docker_helpers_enabled' => false,
    'docker_extra_hosts' => ['host.docker.internal:host-gateway'],
];
