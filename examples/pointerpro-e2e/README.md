# Real-MCP-client end-to-end debug session

Captures a working `php-xdebug-mcp` v0.2.1+ run driving a real Laravel
application (Pointerpro `survey-api`) through every step of a debug
session over JSON-RPC stdio.

## What the recipe proves

- The published `ikarolaborda/php-xdebug-mcp` package, run on the host,
  accepts a connection from Xdebug 3.5.1 inside a Docker container.
- Configured `path_rules` correctly inverse-map the engine's runtime
  path (`/application/...`) to the host workspace (`/home/.../survey-api/...`).
- `xdebug_set_breakpoint` resolves; `xdebug_continue` returns the
  `break` state at the requested line; `xdebug_get_stack` returns
  frames whose `mapped.local_path` points at the host file; and
  `xdebug_get_variables` returns the locals at the breakpoint.
- The HTTP request that triggered Xdebug completes cleanly (HTTP 200)
  after the agent releases the session.

## Setup recap

```
Host PHP 8.5.5 -> bin/php-xdebug-mcp
                   listener 0.0.0.0:9003

Docker (Pointerpro):
  api-php-dev (PHP 8.4.6 + xdebug 3.5.1, mode=debug, trigger)
  api-nginx-dev (HTTPS :443, vhost serves /application/public)

Path rule: /home/iclaborda/Pointerpro/survey-api  <->  /application
Trigger:   curl -k -b 'XDEBUG_SESSION=mcp' https://localhost/
```

## Files

- [`trace.ndjson`](trace.ndjson) — raw JSON-RPC pairs, one per line, in
  the form `{"at": "...", "request": {...}, "response": {...}}`.
- [`timeline.md`](timeline.md) — curated human-readable timeline with
  timestamps and one-line summaries.

## Reproduce locally

The harness lives at `.agent/smoke/pointerpro-e2e.py` (kept in
`.gitignore` because it depends on a specific local Pointerpro
checkout). It does the following:

1. spawn `php bin/php-xdebug-mcp --config=.agent/smoke/pointerpro-config.php`
2. send `initialize` + `notifications/initialized`
3. fire `curl -k -b 'XDEBUG_SESSION=mcp' https://localhost/` in
   the background
4. call `xdebug_wait_for_session`, then `xdebug_claim_session`
5. set a line breakpoint on `app/Http/Controllers/PongController.php:21`
6. call `xdebug_continue`, then `xdebug_get_stack`, then
   `xdebug_get_variables`
7. release and clean up — the FPM worker is unblocked, the curl
   returns HTTP 200

To run against your own Laravel-on-Docker stack, copy
`.agent/smoke/pointerpro-config.php` to `.agent/smoke/<your-config>.php`,
edit the `path_rules` entry, edit the harness's `TARGET_FILE_HOST`,
`TARGET_LINE`, and `TARGET_URL` constants, and invoke.
