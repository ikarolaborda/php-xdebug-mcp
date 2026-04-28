# Troubleshooting

## Xdebug never connects

1. Confirm `php -m | grep -i xdebug` reports xdebug 3.x.
2. Confirm `xdebug.client_host` and `xdebug.client_port` match the
   adapter listener (default `127.0.0.1:9003`).
3. Confirm the trigger is actually present — `XDEBUG_TRIGGER=1`,
   `XDEBUG_SESSION=mcp` cookie, or `xdebug.start_with_request=yes`.
4. Enable Xdebug logging (`xdebug.log=/tmp/xdebug.log`,
   `xdebug.log_level=10`) and look for `Connecting to ...` lines.
5. From inside a Docker container, ensure `host.docker.internal`
   resolves; on Linux Compose set `extra_hosts:
   ["host.docker.internal:host-gateway"]`.

## Tools say `SESSION_AMBIGUOUS`

There is more than one session attached. Call `xdebug_list_sessions` to
see ids, then call the tool with `session_id` set explicitly, or
`xdebug_claim_session` exactly one of them so auto-resolution works.

## `INVALID_SESSION_STATE`

Inspection tools require the session to be at `break` or `stopping`.
If the session is `running`, set a breakpoint and continue, or call
`xdebug_break_execution` (only if `supports_async=1`).

## `BREAKPOINT_VALIDATION_FAILED`

The engine refused a breakpoint. Common causes:

- Line/conditional breakpoint without a usable `file_path`.
- Conditional/watch breakpoint without an expression.
- Line number is out of range or has no executable code on it. Use
  `xdebug_get_executable_lines` to find an adjacent valid line.

## `PATH_MAPPING_FAILED`

The adapter could not resolve the local path to a remote URI. Check
that the path is absolute and that `path_rules` covers it. If you are
in a container, your rule should map the local workspace to the path
mounted inside the container.

## Stop returns disconnected

Expected. `stop` closes the socket without a normal reply; the
adapter treats this as success. The session moves to `stopped`, and
its event log is preserved on the `events` resource.

## My agent can't see eval / set_property

These tools are only registered in `full_control` mode. Set
`safety_mode: full_control` in the config. They are absent — not
disabled — in `control` mode by design.

## Logs go to stdout

They shouldn't. Stdio mode keeps stdout for MCP traffic only. If you
see logs there, you are probably running through a shim that swaps
stderr and stdout. Set `XDEBUG_MCP_LOG_PATH=/tmp/php-xdebug-mcp.log`
to write to a file instead.

## React event-loop deprecation warnings

If running on PHP 8.4+ you may see `E_DEPRECATED` notices originating
from `react/event-loop`. These are upstream and harmless; suppress with
`error_reporting=E_ALL & ~E_DEPRECATED` for the MCP process.
