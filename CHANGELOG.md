# Changelog

All notable changes to **php-xdebug-mcp** are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] - 2026-04-28

### Fixed

- **`feature_get` value parsing.** The DBGp response carries the
  feature value in the response *body text* (not in a child element,
  not in the `supported` attribute — `supported` is a yes/no flag).
  v0.2.0 read the wrong field, which silently corrupted
  `breakpoint_types` to `"1"` against real Xdebug 3.5.1 and made
  `xdebug_set_breakpoint` fail with `FEATURE_UNSUPPORTED: Engine does
  not advertise breakpoint type line. Advertised: 1`. Discovered via
  the new Pointerpro end-to-end harness; regression test added.
- **`tickUntil` predicates that observed primitive variables.** The
  predicates used `static fn () => $captured !== null`. PHP arrow
  functions auto-capture by *value*, so once `$captured` was created
  as `null` the predicate forever saw `null` even after the resolver
  closure (which DOES use `&` by-reference) populated it. Net effect:
  every `xdebug_continue` / `xdebug_step_*` / `xdebug_get_stack` /
  `xdebug_get_variables` / `xdebug_get_property` etc. ran the *full*
  `continuation_timeout_ms` / `inspection_timeout_ms` even when the
  engine had already responded in milliseconds. Switched the affected
  predicates to regular closures with explicit `use (&$captured)`.

### Added

- `examples/pointerpro-e2e/` — curated trace and reproduction notes
  from a real run against a Laravel application running in Docker:
  Xdebug 3.5.1 + PHP-FPM 8.4 + nginx HTTPS. The trace shows the full
  `initialize -> set_breakpoint -> continue -> get_stack ->
  get_variables -> release` flow completing in ~620 ms.

### Performance

- After both fixes, an entire debug session (handshake + breakpoint +
  continue + stack/variables + release) completes in ~600 ms against
  a real Pointerpro Laravel boot, vs. ~30+ seconds before — an
  artefact of the timeout-walking caused by the predicate bug.

## [0.2.0] - 2026-04-28

### BREAKING

- **Minimum PHP version is now 8.5.** PHP 8.3 and 8.4 are no longer
  supported. The CI matrix is reduced to `8.5` only. Composer will
  refuse to install on older runtimes.

### Changed

- All immutable value objects converted to `final readonly class`:
  `BreakpointDefinition`, `InitMetadata`, `PathMappingRule`,
  `PathMappingResult`, `LocalRootSuggestion`, `SessionEvent`,
  `SessionWarning`. `AdapterException` stays plain `final` because it
  extends `RuntimeException`, whose properties aren't readonly.
- Stateless services converted to `final readonly class`: `PathMapper`,
  `AuditLogger`. Stateful services remain `final class`.
- `BreakpointDefinition::withRemoteUri` now uses PHP 8.5
  `clone($this, [...])` for the immutable update.
- `ServerFactory` registrations switched from `static fn () =>
  $instance->method(...)` wrappers to first-class callable syntax
  `$instance->method(...)`. The SDK reflects identical input schemas;
  verified by smoke (`xdebug_set_breakpoint` still exposes all 12
  parameters).
- `PathMapper::findRuleForRemote` rewritten using `array_find` +
  `array_any` (PHP 8.4+) to drop two layers of nested `foreach`.
- Class constants get explicit types where applicable (PHP 8.3+):
  `public const string ID_PATTERN`, `public const string PACKET_*`,
  `public const int MAX_PACKET_BYTES`.

### CI

- GitHub Actions: bumped `actions/checkout@v4` -> `actions/checkout@v5`
  (Node 24 native). Workflow scope sets
  `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true` to silence the Node 20
  deprecation warning across every action.
- Workflow now also runs on `v*` tag pushes so release CI is
  validated.

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

[Unreleased]: https://github.com/ikarolaborda/php-xdebug-mcp/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/ikarolaborda/php-xdebug-mcp/releases/tag/v0.2.1
[0.2.0]: https://github.com/ikarolaborda/php-xdebug-mcp/releases/tag/v0.2.0
[0.1.0]: https://github.com/ikarolaborda/php-xdebug-mcp/releases/tag/v0.1.0
