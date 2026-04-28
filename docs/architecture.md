# Architecture

php-xdebug-mcp is a **debug adapter and session broker**, not a thin pipe
between the MCP client and Xdebug. The public MCP surface stays typed and
predictable, while the DBGp side handles the messy parts: framing, feature
negotiation, command queuing, eventual breakpoint resolution, and graceful
degradation when the engine doesn't advertise an optional capability.

## Boundaries

There are three boundaries the system spans:

1. **MCP transport boundary** — JSON-RPC 2.0 over stdio (or Streamable HTTP
   for remote use). Owned by [`php-mcp/server`](https://github.com/php-mcp/server).
2. **DBGp transport boundary** — TCP listener (default `127.0.0.1:9003`) where
   Xdebug connects back; length-prefixed, NUL-delimited XML.
3. **Workspace ↔ runtime boundary** — local workspace paths versus runtime
   `file://` URIs. Adapter-level path mapping is mandatory; we do not depend
   on Xdebug 3.x native path mapping.

## Module layout

```
src/
  App/                  bootstrap, config, server factory
  Mcp/                  tool handlers, resource handlers, server instructions
    Tools/              SessionTools, ControlTools, BreakpointTools,
                        InspectionTools, IoTools, HelperTools
    Resources/          SessionResources (sessions, stack, breakpoints, events,
                        stdout, stderr, source template)
    SafetyMode.php      enum
    SessionResolver.php disambiguation rules
    ToolResult.php      structured envelope helpers
  Dbgp/
    DbgpListener.php    accepts TCP connections from Xdebug
    DbgpSession.php     one connection + protocol state + command arbiter
    DbgpRuntime.php     always-on pump (stream_select); ticks listener and
                        all session sockets each pass; runs feature
                        negotiation; resolves responses; dispatches
                        notifications; installs persistent breakpoints
                        on session join
    DbgpPacketCodec.php length-prefixed framing decoder + IDE-side encoder
    CommandEncoder.php  argument escaping, base64 -- payload
    ResponseMapper.php  safe XML -> structured records
    FeatureNegotiator.php  probe list + preferred feature_set values
  Domain/
    Sessions/           DebugSession, SessionState, InitMetadata
    Breakpoints/        BreakpointDefinition, BreakpointType,
                        BreakpointScope, HitCondition
    Paths/              PathMappingRule, PathMappingResult, MappingStatus,
                        FrameKind
    Errors/             AdapterErrorCode (enum), AdapterException
    Events/             SessionEvent, EventKind
  Services/             SessionRegistry, SessionClaimManager,
                        BreakpointStore, PathMapper, EventRecorder,
                        OutputBufferStore, AuditLogger,
                        ServerInstructionsBuilder
  Infrastructure/       Clock + SystemClock, Ids, StderrLogger
bin/php-xdebug-mcp      stdio entry point
config/php-xdebug-mcp.php  default configuration
tests/                  Unit, Contract (FakeXdebugEngine), Integration scaffold
docs/                   this folder
examples/               cli / fpm / docker / agent-clients
```

## Concurrency model

We use a single-threaded React event loop. The `DbgpRuntime` pump is always
on: while no MCP tool handler is running, the loop services the listener
and per-session sockets. While a tool is running, the same pump is driven
from `tickUntil(predicate, deadlineMs)` so synchronous-feeling waits keep
making protocol progress.

Per session, commands fall into three slots:

- **inspection / metadata** — exactly one in flight at a time; rejected with
  `COMMAND_IN_FLIGHT` if another command is already in flight.
- **continuation** (run, step_*, stop, detach) — also occupies the in-flight
  slot, but until the engine eventually breaks/stops or the socket closes.
  If the tool's deadline elapses first, the tool returns `still_running`
  while keeping the session alive.
- **break (async interrupt)** — separate slot, only available when the
  engine reports `supports_async=1`; can be sent while a continuation is
  in flight.

## Lifecycle

When Xdebug connects, the runtime accepts the socket, parses `<init>`,
runs feature discovery, applies our preferred `feature_set` values
(`extended_properties`, `notify_ok`, `breakpoint_details`,
`resolved_breakpoints`, `multiple_sessions`, configurable `max_*`), then
installs every matching persistent breakpoint into the session before
exposing it to the agent.

See [docs/lifecycle.md](lifecycle.md) for the complete state diagram.

## Path mapping

`PathMapper` is a first-class subsystem. It supports prefix rules, exact-file
overrides, identity fallback, URL encoding, Windows drive letters, and
synthetic frames (`dbgp://`, `xdebug://`, `eval://`, `internal:`). Synthetic
frames are returned with `mapping_status=not_applicable` and `local_path=null`.
Operations that *require* a mappable file path raise
`PATH_MAPPING_FAILED`; operations that just *display* a frame surface the
synthetic kind with a warning instead of failing.

## Safety modes

`observe` / `control` (default) / `full_control`. Disabled tools are not
registered with the SDK at all — we use static omission rather than deny
stubs so `tools/list` accurately reflects what the current process can do.
Handler-level checks remain as defense in depth for things like claim
state, `allow_stop`, `allow_detach`, and `full_control`-only behaviours.

## Adapter errors

Every tool either returns the structured success envelope:

```json
{ "ok": true, "summary": "...", "data": {...}, "warnings": [...],
  "session": {...}, "next_actions": [...] }
```

or the structured failure envelope:

```json
{ "ok": false, "summary": "...", "error": {
    "code": "BREAKPOINT_VALIDATION_FAILED",
    "message": "...", "dbgp_code": 202, "dbgp_message": "...",
    "session_id": "sess_…", "state": "break", "hint": "..." }
}
```

The full set of error codes lives in
[`src/Domain/Errors/AdapterErrorCode.php`](../src/Domain/Errors/AdapterErrorCode.php).
