# Session lifecycle / state machine

```
                  ┌────────────┐
                  │  starting  │   <init> received, features being negotiated
                  └─────┬──────┘
                        │ run / step_*
                        ▼
              ┌──────────────────┐ async break ┌──────────────────┐
              │      running     │◄────────────│      break       │
              └─────┬───┬────────┘             └──────┬───────────┘
                    │   │ breakpoint hit              │ run / step
                    │   └─────────────────────────────┘
                    │ stop / EOF
                    ▼
              ┌──────────────────┐
              │     stopping     │   may emit performance/profile data
              └─────┬────────────┘
                    │ EOF or normal reply
                    ▼
              ┌──────────────────┐
              │     stopped      │   no further interaction
              └──────────────────┘
                    ▲
                    │ EOF outside normal stop
              ┌──────────────────┐
              │   disconnected   │   adapter-only state
              └──────────────────┘
```

## Allowed operations per state

- **starting** — feature_get/feature_set, breakpoint_*, status. No
  stack/contexts/property work yet.
- **running** — only `break` (when async). Inspection returns
  `INVALID_SESSION_STATE`.
- **break** — full inspection surface; continuation commands; breakpoint
  changes.
- **stopping** — like break, plus the engine may keep the socket open for
  postmortem reads if `supports_postmortem=1`.
- **stopped** / **disconnected** — read-only resources only.

## Continuation timeouts

Continuation tools accept `timeout_ms`. When the timeout elapses without
a break/stop, the tool returns `still_running=true` while keeping the
session alive. The agent can then call `xdebug_wait_for_state` to keep
waiting, or `xdebug_break_execution` if `supports_async=1`.

## Stop / EOF semantics

`stop` typically does not return a normal reply — the socket closes
instead. The adapter watches for that and treats EOF as success when
the last command was `stop` or `detach`. EOF outside that window
transitions the session into `disconnected` and preserves the event log
for post-mortem inspection via the `xdebug://session/{id}/events`
resource.

## Persistent breakpoints

When a new session attaches, every persistent adapter breakpoint is
replayed into the session via `breakpoint_set` and bound to its engine
id. The replay happens before the session is exposed to the agent, so
`xdebug_continue` is safe to call immediately.
