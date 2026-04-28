# Safety modes & dangerous tools

The adapter exposes three safety modes plus two independent toggles for
high-impact operations:

| Setting        | Default      | Effect |
|----------------|--------------|--------|
| `safety_mode`  | `control`    | `observe` / `control` / `full_control` |
| `allow_stop`   | `true`       | Allow `xdebug_stop` to terminate the script |
| `allow_detach` | `true`       | Allow `xdebug_detach` to drop the debugger session |

## What each mode exposes

### `observe`

Read-only. Useful for AIs that should never mutate runtime state.

Tools registered:
- `xdebug_server_status`, `xdebug_list_sessions`, `xdebug_wait_for_session`,
  `xdebug_get_session`, `xdebug_claim_session`, `xdebug_release_session`,
  `php_debug_run_cli`, `php_debug_http_request`.

Resources: all (read-only by definition).

### `control` (default)

Adds the full debugging surface short of arbitrary code execution.

Adds:
- `xdebug_continue`, `xdebug_step_into`, `xdebug_step_over`, `xdebug_step_out`,
  `xdebug_break_execution`, `xdebug_wait_for_state`,
  `xdebug_set_breakpoint`, `xdebug_list_breakpoints`,
  `xdebug_update_breakpoint`, `xdebug_remove_breakpoint`,
  `xdebug_run_to_cursor`,
  `xdebug_get_stack`, `xdebug_get_contexts`, `xdebug_get_variables`,
  `xdebug_get_property`, `xdebug_get_source`, `xdebug_get_executable_lines`,
  `xdebug_get_typemap`,
  `xdebug_configure_output`,
  `xdebug_stop` (if `allow_stop`),
  `xdebug_detach` (if `allow_detach`).

### `full_control`

Adds `xdebug_eval`, `xdebug_set_property`, `xdebug_send_stdin`. These
tools allow arbitrary PHP execution and runtime mutation; only enable
this mode in trusted environments.

### Docker helpers (independent flag)

The Docker helper tools `php_debug_docker_exec` and
`php_debug_docker_run` are gated by a separate config flag,
`docker_helpers_enabled` (default `false`). They shell out to the host
`docker` CLI, so even in `control` mode they are off until you opt in.

Identifier validation: container/service/network/user names must match
`/^[A-Za-z0-9][A-Za-z0-9_.-]*$/`. argv is built with the proc_open
array form (no shell). Volumes must be `host:container[:mode]`.
extra_hosts must be `name:ip`. Anything that fails validation returns
`INVALID_ARGUMENT` and is not invoked.

## Why static omission instead of deny stubs

When a tool is disabled by safety mode it is **not registered** with the
SDK. `tools/list` accurately reflects the surface that the current
process can use, and there's no risk of an agent invoking a tool just
to receive `ACCESS_DENIED`. Handler-level checks remain as defense in
depth (eg. `xdebug_set_property` re-checks the safety mode in the
handler in case future code paths register it incorrectly).

## Network and filesystem hardening

- The DBGp listener binds to `127.0.0.1` by default; non-loopback bind
  must be explicit and is rejected at startup otherwise.
- An optional `allowed_client_ips` list can reject connections from
  unwanted hosts.
- The configured `workspace_roots` define where local file operations
  are allowed; `allow_reads_outside_workspace` opts into broader access.

## Audit log

`AuditLogger` emits one structured line per MCP tool invocation and per
DBGp command summary, redacting the obvious sensitive fields (eval code,
property values, stdin payloads, watch/conditional expressions) to a
`<N bytes>` placeholder. Output goes to the configured logger sink —
`php://stderr` by default, never stdout.
