# Xdebug setup

php-xdebug-mcp expects Xdebug **3.x** with `xdebug.mode=debug`. Xdebug
connects back to the adapter, so the adapter's listener (default
`127.0.0.1:9003`) must be reachable from wherever PHP runs.

## Common base config

```ini
; php.ini or xdebug.ini
zend_extension=xdebug
xdebug.mode=debug
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.start_with_request=trigger
xdebug.idekey=mcp
```

`start_with_request=trigger` is the recommended default. The agent (or
operator) explicitly opts a request into debugging via one of the
documented triggers.

## CLI

Spawn a script with the `XDEBUG_TRIGGER=1` environment variable:

```bash
XDEBUG_TRIGGER=1 php scripts/run.php
```

Or use the helper tool: `php_debug_run_cli` will spawn a child PHP
process with the right env already set, pointing at the configured
listener.

## FPM / web request

Either set the `XDEBUG_SESSION=mcp` cookie, send the
`X-Xdebug-Trigger: mcp` header, or include `XDEBUG_TRIGGER=1` in the
query string. The helper tool `php_debug_http_request` fires a curl-style
request with the cookie already attached.

## Docker

Xdebug runs inside the container; the adapter runs on the host. Configure
`xdebug.client_host` to the gateway address that lets the container reach
the host. On Docker Desktop this is `host.docker.internal`. On Linux,
either set `xdebug.client_host=host.docker.internal` and add
`extra_hosts: ["host.docker.internal:host-gateway"]` in the compose file,
or expose your host IP directly.

`docker-compose.yml` excerpt:

```yaml
services:
  app:
    image: php:8.3-cli
    volumes:
      - ./:/var/www/html
    environment:
      XDEBUG_MODE: debug
      XDEBUG_CONFIG: "client_host=host.docker.internal client_port=9003"
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

The corresponding adapter rule maps the host workspace path to the
in-container path:

```php
// config/php-xdebug-mcp.php
'path_rules' => [
    [
        'label' => 'docker app',
        'local' => '/Users/me/work/app',
        'remote' => '/var/www/html',
    ],
],
```

## Long-running workers / queue consumers

Long-running scripts cannot use `start_with_request`. Two reliable
approaches:

1. Call `xdebug_break()` from PHP at the spot you want to land. This
   triggers a connection and a break in one step.
2. Use `xdebug_connect_to_client()` once per request to attach a session
   on demand.

The adapter's listener is always-on, so it accepts whichever approach
the worker uses.

## Verification checklist

- `php -m | grep -i xdebug` shows xdebug 3.x.
- `php -i | grep xdebug.client_host` matches the adapter address.
- `xdebug_info()` (run on the target side) shows the chosen mode and
  client_host.
- The adapter's stderr log emits `dbgp.session.connected` when a
  triggered request runs.

## Docker-specific quick reference

For full Docker recipes see [docker-recipes.md](docker-recipes.md).
The short version:

- **Container reaches the host** via `xdebug.client_host=host.docker.internal`.
- **On Linux**, add `extra_hosts: ["host.docker.internal:host-gateway"]`
  to the compose service (or `--add-host` to plain `docker run`).
- **PHP-FPM** in a container needs `clear_env = off` in the active pool
  config or env-based triggers will not propagate to workers.
- Enable the `php_debug_docker_exec` and `php_debug_docker_run` MCP
  tools by setting `docker_helpers_enabled=true` in
  `config/php-xdebug-mcp.php` (or
  `XDEBUG_MCP_DOCKER_HELPERS_ENABLED=1`).
- When no rule covers a session's `fileuri`, the adapter attaches a
  `PATH_RULE_MISSING` warning to the session snapshot with a suggested
  `path_rules` entry — apply it before setting breakpoints by
  `file_path`.
