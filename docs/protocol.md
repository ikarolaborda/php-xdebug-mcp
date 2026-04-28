# How MCP talks to Xdebug

This page describes the wire-level boundary between the MCP server and
Xdebug. It's intentionally specific so a future engineer can keep our
implementation honest against the
[official DBGp specification](https://xdebug.org/docs/dbgp).

## Framing

The framing is **asymmetric**. Engine packets are length-prefixed; IDE
commands are not.

```
engine -> IDE :  <ascii-int-length> NUL <XML payload> NUL
IDE -> engine :  <command [args] [-- base64(data)]> NUL
```

`DbgpPacketCodec::drain()` is incremental: it returns zero or more
complete payloads each time it is called, leaving any partial frame in
its internal buffer. The frame size is capped (64 MiB) and a malformed
length prefix or missing trailing NUL throws `ENGINE_PROTOCOL_ERROR`.

## Init

The first packet from the engine is `<init>` carrying `appid`, `idekey`,
`session`, `thread`, `parent`, `language`, `protocol_version`, and
`fileuri`. The runtime captures this verbatim into
`DebugSession::$init`.

## Feature negotiation

We probe the following names via `feature_get`:

```
language_name, language_version, protocol_version, supports_async,
breakpoint_types, multiple_sessions, max_children, max_data, max_depth,
breakpoint_details, extended_properties, notify_ok,
resolved_breakpoints, supported_encodings, supports_postmortem,
show_hidden, data_encoding, language_supports_threads
```

Then we apply preferred `feature_set` values where supported:

```
multiple_sessions=1, extended_properties=1, notify_ok=1,
breakpoint_details=1, resolved_breakpoints=1, show_hidden=1,
max_children=<config>, max_data=<config>, max_depth=<config>
```

A feature that the engine doesn't advertise is silently skipped — never
treated as a failure. Tools that depend on a feature (eg.
`xdebug_break_execution` requires `supports_async`) check it before sending
and return `ASYNC_NOT_SUPPORTED` / `FEATURE_UNSUPPORTED` early.

## Session states

`starting → break ⇄ running ⇄ stopping → stopped`

Plus `disconnected` (adapter-only) when the socket closes outside a normal
stop reply. Inspection commands require `break` or `stopping`. Continuation
commands require `starting` or `break`.

## Breakpoint commands

`breakpoint_set` / `breakpoint_get` / `breakpoint_update` / `breakpoint_remove` /
`breakpoint_list` map directly to our adapter API. Each adapter
breakpoint id is bound to an engine breakpoint id per session via
`DbgpSession::$bpBindings`. `breakpoint_resolved` notifications update the
binding's resolution state without forcing a session-state transition.

## Continuation commands

`run`, `step_into`, `step_over`, `step_out`, `stop`, `detach`. Exactly one
continuation in flight per session. `stop` may close the socket without a
normal reply; that EOF is treated as success.

## Inspection commands

`status`, `stack_get`, `stack_depth`, `context_names`, `context_get`,
`property_get` (paged), `property_value`, `property_set` (full_control),
`source` (begin/end), `eval` (full_control), `typemap_get`, `xcmd_get_executable_lines`.

`property_get` and `context_get` honour `max_children`, `max_data`,
`max_depth` from feature negotiation. `extended_properties=1` triggers
base64 decoding of property values when they contain characters invalid in
XML.

## Notifications

When `notify_ok=1` is negotiated:

- `breakpoint_resolved` updates the per-session binding's resolution
  status and emits a `BreakpointResolved` event.
- `error` is recorded on the event log.
- `stdin` is recorded; we do not push stdin proactively unless
  `xdebug_send_stdin` is called.

## Streams

`stdout` / `stderr` `-c` modes are 0=disable, 1=copy, 2=redirect. When
`copy` or `redirect` is configured, captured chunks are decoded and
appended to `OutputBufferStore` and surfaced via the `xdebug://session/{id}/stdout`
and `…/stderr` resources.

## Optional Xdebug-specific commands

We support these opportunistically, behind feature checks:

- `xcmd_get_executable_lines` — returns the set of lines that may carry
  breakpoints in a given file.
- `xcmd_profiler_name_get` — scaffolded for the optional control-socket
  integration; not exposed in v1.
