# Limitations and roadmap

## v1 (this release)

- stdio MCP transport
- DBGp listener on TCP
- single project / workspace profile per server instance
- session registry, claim/release, persistent + session-scoped breakpoints
- adapter-level path mapping with inverse-mapping diagnostics on session init
- stack / contexts / properties / source / executable lines
- continue / step / break (async only) / stop / detach
- typed adapter errors with DBGp originals preserved
- safety modes via static tool omission + independent allow_stop / allow_detach
- audit logging to stderr
- helper tools to spawn CLI / HTTP debug sessions
- Docker helpers (`php_debug_docker_exec`, `php_debug_docker_run`) gated behind
  `docker_helpers_enabled`, with conservative argv validation and host-gateway
  defaults
- runnable PHP-FPM compose example with `clear_env=off` overlay
- Streamable HTTP transport scaffolded but not the recommended deployment
- xdebugctl / control-socket integration scaffolded but not exposed

## Known limitations

- v1 does not support DBGp proxies (multi-IDE multiplexers).
- Xdebug Cloud is not supported (would require TLS termination on our
  side and per-tenant routing).
- One workspace per server instance — different projects need separate
  processes.
- `php_debug_run_cli` does not stream stdout/stderr from the spawned
  child; agents inspect captured engine streams via the dedicated
  `…/stdout` and `…/stderr` resources.

## Future work

- First-class xdebugctl integration for pausing long-running workers.
- Streamable HTTP + OAuth for shared / remote deployments.
- DBGp proxy support so multiple IDEs can multiplex.
- Streaming child-process I/O for `php_debug_run_cli`.
- Source-content resource (server-side disk read) when explicitly
  enabled by `allow_reads_outside_workspace`.
- Optional native Xdebug path mapping fallback when no rules are
  configured and the engine advertises support.
