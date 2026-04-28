<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\App\Config;

/**
 * Builds the initialization instructions string passed to the SDK via
 * ServerBuilder::withInstructions(). The MCP client surfaces this to the
 * model so the agent learns the preferred workflow.
 */
final class ServerInstructionsBuilder
{
    public function build(Config $cfg): string
    {
        $lines = [];
        $lines[] = '# php-xdebug-mcp';
        $lines[] = 'You are connected to a PHP debug adapter that bridges Xdebug DBGp into MCP.';
        $lines[] = '';
        $lines[] = '## Preferred workflow';
        $lines[] = '1. Call `xdebug_server_status` first to see if a session is already attached.';
        $lines[] = '2. If none, call `xdebug_wait_for_session` (with a timeout) and trigger a debug request from the user side, OR ask the user to do so.';
        $lines[] = '3. Call `xdebug_claim_session` to take exclusive control.';
        $lines[] = '4. Set breakpoints with `xdebug_set_breakpoint` using local workspace paths (the adapter maps them to the runtime URI).';
        $lines[] = '5. `xdebug_continue` until a break, then inspect: `xdebug_get_stack`, `xdebug_get_contexts`, `xdebug_get_variables`, `xdebug_get_property`, `xdebug_get_source`.';
        $lines[] = '6. Step with `xdebug_step_into` / `xdebug_step_over` / `xdebug_step_out`. Use `xdebug_run_to_cursor` for a one-shot temporary line breakpoint.';
        $lines[] = '7. When the investigation is finished, `xdebug_release_session` (and optionally `xdebug_stop` or `xdebug_detach` if allowed by config).';
        $lines[] = '';
        $lines[] = '## Important behaviors';
        $lines[] = '- This server does not expose a raw DBGp tunnel. Operations are typed and validated.';
        $lines[] = '- Tools are gated by safety mode (current: `' . $cfg->safetyMode->value . '`).';
        $lines[] = '- `xdebug_break_execution` is only available if the engine reports `supports_async=1`.';
        $lines[] = '- Continuation tools may take time. They return either a structured "still running" result or the new break/stop state. Keep your timeout reasonable.';
        $lines[] = '- Synthetic frames (eval/internal) come back with `mapping_status=not_applicable`. Do not pass their `local_path` to other tools.';
        $lines[] = '- All tool results follow the envelope `{ok, summary, data, warnings, session, next_actions}`.';
        $lines[] = '';
        $lines[] = '## Resources';
        $lines[] = '- `xdebug://sessions`, `xdebug://session/{id}`, `xdebug://session/{id}/stack`, `xdebug://session/{id}/breakpoints`, `xdebug://session/{id}/events`, `xdebug://session/{id}/stdout`, `xdebug://session/{id}/stderr`.';
        $lines[] = '- `xdebug://session/{id}/source/{path}` for source retrieval (URL-encoded path).';
        $lines[] = '';
        $lines[] = '## Path mapping';
        $lines[] = 'Workspace roots: ' . implode(', ', $cfg->workspaceRoots);
        if ($cfg->pathRules === []) {
            $lines[] = 'No explicit path rules are configured. Identity mapping is used when no rule matches.';
        } else {
            $lines[] = 'Configured rules:';
            foreach ($cfg->pathRules as $rule) {
                $label = $rule->label !== '' ? $rule->label : 'rule';
                $lines[] = '- ' . $label . ': ' . $rule->localRoot . ' <-> ' . $rule->remoteRoot;
            }
        }

        if ($cfg->dockerHelpersEnabled) {
            $lines[] = '';
            $lines[] = '## Docker workflow';
            $lines[] = 'Two helper tools are enabled: `php_debug_docker_exec` and `php_debug_docker_run`.';
            $lines[] = '- For a running container or compose service: call `php_debug_docker_exec` with `container_or_service` and a PHP `script` path *inside the container*. The helper injects `XDEBUG_TRIGGER=1` and `XDEBUG_CONFIG=client_host=host.docker.internal client_port=' . $cfg->listenPort . '`.';
            $lines[] = '- For an ephemeral container: call `php_debug_docker_run` with `image` and a `command` array. By default `--add-host host.docker.internal:host-gateway` is set so Linux hosts work without compose.';
            $lines[] = '- After spawning, call `xdebug_wait_for_session` — completion of docker exec/run does NOT mean Xdebug has connected yet.';
            $lines[] = '- If the engine reports a fileuri that no path rule covers, the session snapshot includes a `PATH_RULE_MISSING` warning with a suggested rule. Apply it before setting breakpoints by file_path.';
            $lines[] = '- PHP-FPM in containers: ensure `clear_env=off` in the FPM pool config — otherwise `XDEBUG_TRIGGER` env vars are stripped from worker processes.';
        }

        return implode("\n", $lines);
    }
}
