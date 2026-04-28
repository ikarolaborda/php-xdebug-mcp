# PHP-FPM in compose, agent on the host

Demonstrates the most common Docker layout: nginx + PHP-FPM in compose,
the MCP server running on the host with `docker_helpers_enabled=true`.

## Bring it up

```bash
cd examples/docker/php-fpm-compose
docker compose build
docker compose up -d
```

## Configure the adapter

Set the path rule and enable the docker helpers, eg in
`config/php-xdebug-mcp.php`:

```php
'path_rules' => [
    [
        'label' => 'fpm-compose example',
        'local' => __DIR__ . '/../examples/docker/php-fpm-compose/public',
        'remote' => '/var/www/html',
    ],
],
'docker_helpers_enabled' => true,
```

(`XDEBUG_MCP_DOCKER_HELPERS_ENABLED=1` does the same.)

## Drive a debug session

In the agent:

1. `xdebug_wait_for_session` (timeout 60000)
2. Trigger the request via either:
   - `php_debug_http_request` with
     `url=http://localhost:8080/index.php?user=alice` (sets the
     `XDEBUG_SESSION` cookie automatically), or
   - `php_debug_docker_exec` with
     `container_or_service=php-fpm-compose-php-fpm-1`,
     `script=/var/www/html/index.php`, `use_compose=false` (CLI inside
     the FPM container)
3. `xdebug_claim_session`
4. `xdebug_set_breakpoint` with
   `file_path=<absolute host path to public/index.php>` and
   `lineno=11`
5. `xdebug_continue`, then `xdebug_get_stack` and
   `xdebug_get_variables` once it breaks

## Why `clear_env = off`

PHP-FPM defaults to `clear_env = on`, which strips the worker
environment before each request. That removes `XDEBUG_TRIGGER` /
`XDEBUG_MODE` env vars, and any env-based trigger silently fails. The
`docker/zz-clear-env-off.conf` overlay sets it to off so triggers
propagate to the worker process.

## Why `extra_hosts: ["host.docker.internal:host-gateway"]`

On Docker Desktop (Mac/Windows) the alias resolves automatically. On
plain Linux Docker it does not — the container has no way to reach the
host without explicit configuration. `host-gateway` is the
cross-platform pattern recommended in the docker-compose reference.
