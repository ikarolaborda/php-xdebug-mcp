# Security policy

## Supported versions

Only the latest published `main` branch and the most recent tagged
release receive security fixes. There is no LTS/maintenance branch.

| Version          | Supported |
|------------------|-----------|
| `main` / latest  | yes       |
| older tagged     | no        |

## Reporting a vulnerability

Please report security issues privately. Do **not** open a public
issue.

- Email: **iclaborda@msn.com**
- Or use GitHub's *Report a vulnerability* link on the **Security**
  tab.

Include enough detail to reproduce the issue (PHP/Xdebug versions,
adapter config, minimal repro). I'll acknowledge within 5 working
days. Coordinated disclosure timelines depend on severity but I aim
for a fix or mitigation within 30 days for high-impact issues.

## Threat model

php-xdebug-mcp is a debug adapter that gives a connected MCP client
live control over a running PHP runtime. The threat model assumes:

- The MCP client is **trusted** (it can already issue
  `xdebug_eval` / `xdebug_set_property` / `xdebug_send_stdin` if you
  enable `full_control`).
- The DBGp listener is **trusted within its bind address**. The
  default `127.0.0.1` keeps it host-local; non-loopback bind requires
  explicit configuration and is documented as for trusted environments
  only.
- The Xdebug engine connecting back is **trusted to be a real Xdebug**
  on a process you own. Hostile engines on a shared loopback can
  corrupt session state — use `allowed_client_ips` to restrict.

What the adapter does to limit blast radius:
- conservative argv validation for Docker helper tools
  (`/^[A-Za-z0-9][A-Za-z0-9_.-]*$/` for container/service/network/user
  identifiers; volume + extra_host validators; `proc_open` array form,
  never shell)
- safety modes (`observe` / `control` / `full_control`) statically omit
  dangerous tools at registration time
- audit logging of all MCP tool invocations and DBGp command summaries
  (sensitive payloads are length-redacted in logs)
- stdout-cleanliness regression test ensuring secrets/logs never leak
  to the MCP transport channel

What is **out of scope**:
- An attacker who can already configure Xdebug on the target host has
  full control of the PHP runtime regardless.
- An attacker who can read MCP client process memory can intercept
  whatever travels on the JSON-RPC channel.
- Streamable HTTP transport is scaffolded but not the recommended
  deployment story; use it on trusted internal networks only.
