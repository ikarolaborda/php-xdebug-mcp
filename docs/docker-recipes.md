# Docker recipes

php-xdebug-mcp is designed to be **Docker-aware out of the box**. The
adapter runs on the host, Xdebug runs inside the container, and the
container connects back to the adapter's loopback listener. This page
documents the four common layouts and the gotchas that bite people when
they wire them up for the first time.

## Quick orientation

| Where the adapter runs | Where Xdebug runs            | What needs to be configured |
|------------------------|-----------------------------|------------------------------|
| host                   | container (CLI)             | `xdebug.client_host=host.docker.internal`, helper or `docker exec` |
| host                   | container (PHP-FPM)         | same as above + `clear_env=off` in FPM pool |
| host                   | container (queue worker)    | `xdebug_break()` or `xdebug_connect_to_client()` from worker code |
| container              | another container (compose) | bind listener on `0.0.0.0`; both join the same network |

## 1. Common base config

In your container image, drop a `99-xdebug.ini`:

```ini
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=trigger
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
xdebug.idekey=mcp
xdebug.discover_client_host=0
xdebug.log_level=3
```

`discover_client_host` is great for shared dev servers but unhelpful for
Docker — keep it `0` and rely on the explicit `client_host`.

## 2. Compose `extra_hosts` for Linux hosts

Docker Desktop (Mac / Windows) resolves `host.docker.internal`
automatically. **Plain Linux Docker does not** unless you tell it to:

```yaml
services:
  app:
    image: php:8.3-cli
    volumes:
      - ./:/var/www/html
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

Without that line, `host.docker.internal` is a name-resolution failure
inside the container and Xdebug silently never connects. The MCP
server's stderr log will show no `dbgp.session.connected` event.

If `host-gateway` isn't enough (some older Linux setups), set
`xdebug.client_host` to the explicit gateway IP (`172.17.0.1` is the
default Docker bridge gateway) or run the container with
`network_mode: host` and use `127.0.0.1`.

### IMPORTANT: bind the listener on a non-loopback address

On **Linux** the container reaches the host via the bridge gateway IP
(eg. `172.17.0.1`), **not** via loopback. A listener bound to
`127.0.0.1` is invisible to the container — Xdebug logs

```
[Step Debug] WARN: Creating socket for 'host.docker.internal:9003',
  poll success, but error: Operation now in progress (29).
[Step Debug] ERR: Could not connect to debugging client.
```

Bind on `0.0.0.0` so the listener accepts on every interface. Lock
down with `allowed_client_ips` if untrusted processes share the host.

```bash
XDEBUG_MCP_LISTEN_HOST=0.0.0.0 php bin/php-xdebug-mcp
```

```php
// or in config/php-xdebug-mcp.php
'listen_host' => '0.0.0.0',
'allowed_client_ips' => ['172.17.0.0/16', '172.18.0.0/16'],
```

On Docker Desktop the userland proxy routes `host.docker.internal` to
the host's loopback automatically, so `127.0.0.1` works there. The
Linux case is the gotcha; we recommend just always using `0.0.0.0`
for Docker-shaped dev environments.

## 3. PHP-FPM in a container — the `clear_env` trap

PHP-FPM defaults to `clear_env = on` in `www.conf`. With that default,
the FastCGI worker sees an *empty* environment, so any
`XDEBUG_TRIGGER`/`XDEBUG_MODE` env vars you pass on the request are
stripped before they reach Xdebug. Symptoms: no `dbgp.session.connected`
event, but `xdebug.log` shows nothing either.

Fix: in the active FPM pool config (`/usr/local/etc/php-fpm.d/www.conf`
in the official image), set `clear_env = off` and reload FPM. Once that
is done, the cookie-based trigger works:

```bash
curl -b 'XDEBUG_SESSION=mcp' http://localhost:8080/index.php
```

Or use the `php_debug_http_request` MCP tool — it sets the cookie for
you.

## 4. Driving a CLI script inside a running container

When `docker_helpers_enabled=true`, two new MCP tools are registered:

```jsonc
// 1. Wait for a session
{ "name": "xdebug_wait_for_session", "arguments": { "timeout_ms": 30000 } }

// 2. Spawn a PHP CLI script inside the running compose service "app"
{
  "name": "php_debug_docker_exec",
  "arguments": {
    "container_or_service": "app",
    "use_compose": true,
    "script": "/var/www/html/bin/console",
    "args": ["app:debug-target"],
    "working_dir": null
  }
}

// 3. Claim the new session and start setting breakpoints
{ "name": "xdebug_claim_session" }
```

`docker_helpers_enabled` defaults to **false**. Set it via env
(`XDEBUG_MCP_DOCKER_HELPERS_ENABLED=1`) or via `config/php-xdebug-mcp.php`.

## 5. Long-running queue workers (Symfony Messenger, Laravel queue)

Workers cannot use `start_with_request`. Two reliable approaches:

a) Sprinkle `xdebug_break();` at the spot you want to land. The first
worker iteration that hits it will connect back to the adapter and
break.

b) Set up the worker to call `xdebug_connect_to_client()` once per
message, e.g. wired via a Symfony Messenger middleware in `dev`. This
opens a fresh connection per message, the adapter accepts it, and the
agent claims it normally.

Both work the same way through the MCP server: you call
`xdebug_wait_for_session`, the worker hits `xdebug_break()` (or
`xdebug_connect_to_client()`), and you take it from there.

## 6. Ephemeral container probes (`docker run`)

For one-off probes — eg. running a Composer script against a known
debug image — use `php_debug_docker_run`:

```jsonc
{
  "name": "php_debug_docker_run",
  "arguments": {
    "image": "ghcr.io/yourorg/php-xdebug:8.3",
    "command": ["php", "/work/probe.php"],
    "volumes": ["/host/path/to/work:/work"],
    "network": "myproject_default"
  }
}
```

The default `extra_hosts` is `["host.docker.internal:host-gateway"]`,
so the listener is reachable on Linux without further config. Pass
`network` to join the same compose network as your FPM/database
services if the probe needs to reach them.

## 7. Adapter-running-in-a-container

If you put the MCP server itself in a container, two things change:

- The listener must bind to `0.0.0.0`, not `127.0.0.1`. Set
  `XDEBUG_MCP_LISTEN_HOST=0.0.0.0` and lock it down with `allowed_client_ips`.
- The Xdebug clients are reaching it via the container's network, so
  `xdebug.client_host` becomes the adapter container's hostname or its
  IP on the shared network — not `host.docker.internal`.

This setup is fine for trusted internal use; it is not a production
deployment story. Streamable HTTP transport (scaffolded, not enabled
by default) is the path for that.

## 8. Path-mapping diagnostics

When a session connects, the adapter inspects the engine's `fileuri`
and checks it against the configured rules. If no rule covers it, you
get a structured warning attached to the session snapshot:

```jsonc
"warnings": [
  {
    "code": "PATH_RULE_MISSING",
    "message": "Engine session reports a fileuri (file:///var/www/html/app/Index.php) that no configured path rule covers.",
    "context": {
      "remote_fileuri": "file:///var/www/html/app/Index.php",
      "suggested_rule": {
        "local_root": "/Users/me/projects/myapp",
        "remote_root": "/var/www/html",
        "overlap_segments": 2
      }
    },
    "hint": "Try adding a path_rules entry: local=/Users/me/projects/myapp, remote=/var/www/html"
  }
]
```

Apply the suggestion or write a custom rule, restart the adapter, and
the warning disappears. The diagnostic only fires when **at least one
rule is configured** — the identity-mapping case (no rules) is
intentional and surfaces a softer warning under
`mapped.warnings` instead.

## 9. Security notes

- `php_debug_docker_exec` and `php_debug_docker_run` shell out to the
  `docker` CLI on the host. Container/service/network/user names are
  validated against `/^[A-Za-z0-9][A-Za-z0-9_.-]*$/` and argv is built
  with the proc_open array form — no shell expansion. Even so, treat
  these tools as privileged: only enable them in trusted MCP clients
  and never let an untrusted agent set the `image` argument freely.
- Audit log entries name the container and command for every spawn.
- Default `extra_hosts` is `host.docker.internal:host-gateway`.
  Override via `docker_extra_hosts` config if your Docker daemon needs
  a different alias.

## 10. Troubleshooting checklist

1. From inside the container: `getent hosts host.docker.internal` —
   does it resolve?
2. From inside the container: `php -r 'echo getenv("XDEBUG_TRIGGER");'`
   — non-empty? (FPM pool `clear_env=on` will print empty.)
3. Adapter stderr log: `tail -f /tmp/php-xdebug-mcp.log` — do you see
   `dbgp.session.connected`?
4. Container Xdebug log
   (`xdebug.log=/tmp/xdebug.log`, `xdebug.log_level=10`) — does it show
   `Connecting to ...`? If yes but there's no `dbgp.session.connected`
   on the host, network reachability is the issue.
5. `php_debug_docker_exec` argv field on the result: copy-paste it into
   a host shell and confirm it runs.
