# php-xdebug-mcp

[![tests](https://github.com/ikarolaborda/php-xdebug-mcp/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/ikarolaborda/php-xdebug-mcp/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-%5E8.5-777bb4?logo=php&logoColor=white)](https://www.php.net/releases/8.5/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> A production-grade **Model Context Protocol** server that exposes
> **Xdebug step debugging via DBGp** as typed, safe, model-friendly tools
> and resources. Treat it as an IDE-grade debug adapter for AI coding
> agents — not as a raw protocol tunnel.

php-xdebug-mcp lets an AI agent attach to a live PHP process, set
breakpoints by local workspace path, step through code, inspect stack
frames and variables, and (when explicitly opted in) evaluate
expressions against the running runtime. It works for plain CLI
scripts, PHP-FPM behind nginx, queue workers, and PHP running inside
Docker containers.

It is built on
[`php-mcp/server`](https://github.com/php-mcp/server) and the
[Xdebug DBGp protocol](https://xdebug.org/docs/dbgp).

---

## Highlights

- **31 typed MCP tools** covering session lifecycle, breakpoints (line /
  conditional / exception / call / return / watch), stepping,
  inspection, source retrieval, executable-line discovery, stdout/stderr
  capture, and Docker helpers.
- **Adapter-level path mapping** with rule-based prefix translation,
  exact-file overrides, URL encoding, Windows drive letters, and
  synthetic-frame normalisation (eval / internal / unknown).
- **Inverse-mapping diagnostics**: when a session connects with a
  `fileuri` no rule covers, the adapter attaches a structured
  `PATH_RULE_MISSING` warning to the session snapshot — including a
  best-effort *suggested* `path_rules` entry derived from your
  workspace roots.
- **Safety modes** (`observe` / `control` / `full_control`) implemented
  via static omission at registration time so `tools/list` reflects
  exactly what the current process can do — never deny stubs that show
  up just to refuse.
- **Docker-aware**: optional helpers `php_debug_docker_exec` and
  `php_debug_docker_run` build argv via the proc_open array form,
  validate identifiers conservatively, and default
  `--add-host host.docker.internal:host-gateway` so Linux containers
  reach the host listener.
- **Stdio-clean**: stdout is reserved for MCP traffic; logs go to
  stderr (or any configured PSR-3 sink). A regression test asserts
  this even on bootstrap failure.
- **PHP 8.5 native**: the codebase uses `final readonly class` for value
  objects, first-class callable syntax for handler registrations,
  `clone($obj, [...])` for immutable updates, and `array_find` /
  `array_any` where they cleanly express intent. CI runs the
  Unit + Contract suites on PHP 8.5 against every push.
- **Tested**: 67 unit + contract tests, deterministic and Docker-free.
  Real-Xdebug integration scenarios are scaffolded as runnable examples.

---

## Quickstart

### 1. Install

Requires **PHP 8.5+**. Install via Composer:

```bash
composer require ikarolaborda/php-xdebug-mcp
```

Or clone and use directly:

```bash
git clone https://github.com/ikarolaborda/php-xdebug-mcp.git
cd php-xdebug-mcp
composer install
```

### 2. Configure Xdebug

Drop a `99-xdebug.ini` into your PHP environment:

```ini
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=trigger
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.idekey=mcp
```

For Docker, replace `127.0.0.1` with `host.docker.internal` and add
`extra_hosts: ["host.docker.internal:host-gateway"]` to your compose
service. See [docs/docker-recipes.md](docs/docker-recipes.md) for the
full story including the PHP-FPM `clear_env` trap and the Linux
loopback gotcha.

### 3. Wire the MCP client

Point any MCP client at the binary over stdio. Examples for the most
common clients live in
[`examples/agent-clients/`](examples/agent-clients/):

```jsonc
// Claude Code  ~/.claude.json
{
  "mcpServers": {
    "php-xdebug-mcp": {
      "command": "php",
      "args": ["/abs/path/to/php-xdebug-mcp/bin/php-xdebug-mcp"],
      "env": {
        "XDEBUG_MCP_LOG_PATH": "/tmp/php-xdebug-mcp.log",
        "XDEBUG_MCP_SAFETY_MODE": "control"
      }
    }
  }
}
```

### 4. Drive a debug session

The agent's typical flow:

```
xdebug_server_status      # is the listener up?
xdebug_wait_for_session   # block until Xdebug connects
xdebug_claim_session      # take exclusive control
xdebug_set_breakpoint     # by local file_path + lineno
xdebug_continue           # to the breakpoint
xdebug_get_stack          # frames with normalised path mapping
xdebug_get_variables      # locals at the current frame
xdebug_step_over          # step
xdebug_release_session    # done
```

The full set of tools is described inline in
[`docs/architecture.md`](docs/architecture.md).

---

## Architecture

```
[ MCP client / agent ]  <-- JSON-RPC 2.0 over stdio -->  [ php-mcp/server SDK ]
                                                                |
                                                                +-- typed tools / resources / prompts
                                                                |
                                                                +-- DbgpRuntime (always-on react/event-loop pump)
                                                                            |
                                                                            +-- TCP DBGp listener
                                                                                  |
                                                                                  Xdebug ----> connects back here
```

Three boundaries make Docker deployments work cleanly:

| Boundary           | Owned by      | Notes |
|--------------------|---------------|-------|
| MCP transport      | php-mcp/server | stdio (default) or Streamable HTTP (scaffolded) |
| DBGp transport     | this project   | length-prefixed XML, NUL-delimited, asymmetric framing |
| Workspace ↔ runtime| this project   | adapter-level path mapping with diagnostics |

Long-form: [`docs/architecture.md`](docs/architecture.md).

---

## Safety modes

| Mode                  | Read-only resources | Breakpoints / stepping / inspection | Eval & property mutation | Stdin push |
|-----------------------|---------------------|-------------------------------------|--------------------------|------------|
| `observe`             | yes                 | no                                  | no                       | no         |
| `control` (default)   | yes                 | yes                                 | no                       | no         |
| `full_control`        | yes                 | yes                                 | yes                      | yes        |

`xdebug_stop` and `xdebug_detach` are independently gated by
`allow_stop` and `allow_detach`. Docker helpers are gated by
`docker_helpers_enabled` (default false).

Disabled tools are **not registered** with the MCP server, so
`tools/list` accurately reflects the surface that the current process
can use.

See [`docs/safety-modes.md`](docs/safety-modes.md).

---

## Tools at a glance

**Session** — `xdebug_server_status`, `xdebug_wait_for_session`,
`xdebug_list_sessions`, `xdebug_get_session`, `xdebug_claim_session`,
`xdebug_release_session`.

**Control** — `xdebug_continue`, `xdebug_step_into`, `xdebug_step_over`,
`xdebug_step_out`, `xdebug_break_execution` (async only),
`xdebug_wait_for_state`, `xdebug_stop`, `xdebug_detach`.

**Breakpoints** — `xdebug_set_breakpoint`, `xdebug_list_breakpoints`,
`xdebug_update_breakpoint`, `xdebug_remove_breakpoint`,
`xdebug_run_to_cursor`.

**Inspection** — `xdebug_get_stack`, `xdebug_get_contexts`,
`xdebug_get_variables`, `xdebug_get_property`, `xdebug_get_source`,
`xdebug_get_executable_lines`, `xdebug_get_typemap`,
`xdebug_eval` (full_control), `xdebug_set_property` (full_control).

**I/O** — `xdebug_configure_output`,
`xdebug_send_stdin` (full_control).

**Helpers** — `php_debug_run_cli`, `php_debug_http_request`,
`php_debug_docker_exec` (gated), `php_debug_docker_run` (gated).

**Resources** — `xdebug://sessions`, `xdebug://session/{id}`,
`xdebug://session/{id}/{stack,breakpoints,events,stdout,stderr}`,
`xdebug://session/{id}/source/{path}` template.

---

## Configuration

`config/php-xdebug-mcp.php` ships with sensible defaults; environment
variables override (`XDEBUG_MCP_*`). Notable knobs:

| Key                        | Env override                          | Default                       | Notes |
|----------------------------|---------------------------------------|-------------------------------|-------|
| `listen_host`              | `XDEBUG_MCP_LISTEN_HOST`              | `127.0.0.1`                   | Use `0.0.0.0` for Docker on Linux |
| `listen_port`              | `XDEBUG_MCP_LISTEN_PORT`              | `9003`                        | Xdebug 3 default |
| `safety_mode`              | `XDEBUG_MCP_SAFETY_MODE`              | `control`                     | `observe` / `control` / `full_control` |
| `allow_stop`               | —                                     | `true`                        | gates `xdebug_stop` |
| `allow_detach`             | —                                     | `true`                        | gates `xdebug_detach` |
| `path_rules[]`             | —                                     | `[]`                          | local ↔ remote prefix rules |
| `workspace_roots[]`        | —                                     | `[cwd()]`                     | used by inverse-mapping suggestions |
| `continuation_timeout_ms`  | —                                     | `30000`                       | per-tool deadline for run/step |
| `inspection_timeout_ms`    | —                                     | `5000`                        | per-tool deadline for stack/locals |
| `docker_helpers_enabled`   | `XDEBUG_MCP_DOCKER_HELPERS_ENABLED`   | `false`                       | exposes the two docker helpers |
| `docker_extra_hosts[]`     | —                                     | `host.docker.internal:host-gateway` | passed to docker run as `--add-host` |
| `log_path`                 | `XDEBUG_MCP_LOG_PATH`                 | `php://stderr`                | stdout MUST stay clean in stdio mode |

---

## Documentation

- [Architecture](docs/architecture.md)
- [Protocol notes (DBGp)](docs/protocol.md)
- [Xdebug setup (CLI / FPM / Docker / workers)](docs/xdebug-setup.md)
- [Docker recipes](docs/docker-recipes.md) — compose, FPM `clear_env`, Linux loopback gotcha
- [Path mapping cookbook](docs/path-mapping.md)
- [Session lifecycle / state machine](docs/lifecycle.md)
- [Safety modes & dangerous tools](docs/safety-modes.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Security model](docs/security.md)
- [Limitations and roadmap](docs/roadmap.md)

---

## Examples

| Folder                                                | Layout |
|-------------------------------------------------------|--------|
| [`examples/cli/`](examples/cli/)                      | host PHP CLI script driven via `php_debug_run_cli` |
| [`examples/fpm/`](examples/fpm/)                      | FPM behind nginx on the host |
| [`examples/docker/`](examples/docker/)                | Docker compose with bind-mounted source |
| [`examples/docker/php-fpm-compose/`](examples/docker/php-fpm-compose/) | full FPM-in-compose recipe with `clear_env=off` |
| [`examples/agent-clients/`](examples/agent-clients/)  | Claude Code, Codex, Copilot config snippets |
| [`examples/pointerpro-e2e/`](examples/pointerpro-e2e/) | curated real-MCP-client trace (handshake → break → stack → variables) against a Laravel app in Docker |

---

## Tests

```bash
composer install
vendor/bin/phpunit                          # all suites
vendor/bin/phpunit --testsuite=Unit         # codec, mapper, services, gating
vendor/bin/phpunit --testsuite=Contract     # FakeXdebugEngine + path diagnostics
vendor/bin/phpunit --testsuite=Integration  # skipped without Xdebug installed
```

CI runs Unit + Contract on PHP 8.5 against every push and PR
to `main`.

---

## Contributing

Bug reports and PRs welcome. Please:

- match the existing PSR-12 style (typed properties, single quotes,
  early returns, no else where early return reads cleaner);
- run the full test suite and add a focused test alongside any
  behavioural change;
- keep commits focused; the scope of `composer.json` is the
  source-of-truth dependency surface and we intentionally keep it
  small.

---

## License

[MIT](LICENSE).
