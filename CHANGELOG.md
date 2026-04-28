# Changelog

All notable changes to **php-xdebug-mcp** are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-04-28

### Added

- Initial public release.
- Stdio MCP transport built on `php-mcp/server` 3.3.
- TCP DBGp listener with always-on react/event-loop pump that ticks
  the listener and per-session sockets between tool calls and during
  long synchronous waits via `tickUntil(predicate, deadlineMs)`.
- Per-session command arbiter: one normal/continuation command in
  flight at a time, plus a separate async-break interrupt slot that
  activates only when the engine reports `supports_async=1`.
- Adapter-level path mapping with prefix rules, exact-file overrides,
  URL encoding, Windows drive letters, identity fallback, and
  synthetic-frame normalisation (`FrameKind` ∈
  `file/eval/internal/unknown` × `MappingStatus` ∈
  `mapped/unmapped/not_applicable/failed`).
- Inverse-mapping diagnostic: when a session connects with a
  `fileuri` no rule covers, the adapter attaches a structured
  `PATH_RULE_MISSING` warning to the session snapshot with a
  best-effort suggested rule derived from `workspace_roots`.
- Persistent + session-scoped breakpoints with adapter→engine id
  bindings and `breakpoint_resolved` notification handling.
- 31 typed MCP tools across session, control, breakpoint, inspection,
  IO, and helper categories. Safety modes (`observe` / `control` /
  `full_control`) implemented via static omission at registration time
  so `tools/list` accurately reflects the surface.
- Docker helpers `php_debug_docker_exec` and `php_debug_docker_run`
  gated behind `docker_helpers_enabled`. argv built via the
  `proc_open` array form; identifier regex validation; default
  `--add-host host.docker.internal:host-gateway` for Linux.
- Server initialization instructions surface a Docker workflow snippet
  when helpers are enabled (mentions `host.docker.internal`, Linux
  `extra_hosts`, PHP-FPM `clear_env=off`, async connect race).
- Resources: `xdebug://sessions`, `xdebug://session/{id}`,
  `xdebug://session/{id}/{stack,breakpoints,events,stdout,stderr}`,
  and `xdebug://session/{id}/source/{path}` template.
- Stdout-cleanliness regression test asserting that bootstrap failure
  never leaks to the MCP transport channel.
- 67 phpunit tests (Unit + Contract via `FakeXdebugEngine`) running
  green on PHP 8.3 and 8.4 in CI.
- Runnable `examples/docker/php-fpm-compose/` recipe (compose +
  Dockerfile + `clear_env=off` overlay + nginx + `index.php` +
  README) with verified end-to-end Tier-2 smoke against Xdebug 3.5.

### Documentation

- README, architecture, DBGp protocol notes, Xdebug setup, Docker
  recipes, path-mapping cookbook, lifecycle, safety modes,
  troubleshooting, security model, roadmap.
- Agent-client config snippets for Claude Code, Codex, GitHub Copilot
  / VS Code.

### Known limitations

- Streamable HTTP transport is scaffolded but not enabled by default.
- xdebugctl / control-socket integration is scaffolded but not
  exposed.
- Real-Xdebug end-to-end MCP-driven flow is documented in
  `examples/docker/php-fpm-compose/` but not gated as a CI run.

[Unreleased]: https://github.com/ikarolaborda/php-xdebug-mcp/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ikarolaborda/php-xdebug-mcp/releases/tag/v0.1.0
