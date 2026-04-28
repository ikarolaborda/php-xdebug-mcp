# Security model

php-xdebug-mcp gives an agent live control over a PHP runtime. Treat it
like remote `gdb` over a network: powerful, useful, and never to be
exposed to untrusted clients.

## Default posture

- DBGp listener binds to `127.0.0.1` only.
- MCP transport is **stdio**, which means the client launches the
  server as a child process.
- `safety_mode=control` — no `eval`, no property mutation, no stdin push.
- `allow_stop=true`, `allow_detach=true` — these are dangerous but the
  agent already has stepping control; flipping them to `false` only
  helps if you specifically want to forbid termination.

## Network exposure

If you enable Streamable HTTP (`http_enabled=true`), the bind address is
`127.0.0.1` by default. If you change it, you also need:

- An authentication layer in front (reverse proxy with mutual TLS,
  signed bearer tokens, or an SSH tunnel). The MCP spec recommends OAuth
  for HTTP transports — this server does not implement that today; treat
  Streamable HTTP as for trusted internal use only.
- An explicit `allowed_client_ips` list if Xdebug clients are also
  reaching the listener over the network.

## Filesystem access

`workspace_roots` is the allowlist for local file reads when an MCP
client requests source via the source resource. `allow_reads_outside_workspace`
relaxes that — keep it `false` unless you have a reason.

## Sensitive data

The audit logger redacts known-dangerous fields (`code`, `expression`,
`value`, `data`, `stdin`) to length placeholders. Bring your own
log-rotation if you need formal retention guarantees, since the default
sink is just `php://stderr`.

## Threats we don't defend against

- An attacker who can already configure Xdebug on the target host has
  full control of the PHP runtime; no MCP layer can change that.
- An attacker who can read the MCP client process memory can steal
  whatever is exchanged on the JSON-RPC channel.
- We do not protect against malicious *engine* responses: a hostile
  Xdebug-look-alike on the loopback can corrupt session state. Use
  the IP allowlist if untrusted local processes might attempt to
  connect.
