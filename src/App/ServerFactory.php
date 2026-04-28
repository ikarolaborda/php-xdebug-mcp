<?php

declare(strict_types=1);

namespace PhpXdebugMcp\App;

use PhpMcp\Schema\ServerCapabilities;
use PhpMcp\Server\Server;
use PhpMcp\Server\ServerBuilder;
use PhpXdebugMcp\Dbgp\DbgpListener;
use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Infrastructure\Clock;
use PhpXdebugMcp\Infrastructure\StderrLogger;
use PhpXdebugMcp\Infrastructure\SystemClock;
use PhpXdebugMcp\Mcp\Resources\SessionResources;
use PhpXdebugMcp\Mcp\SafetyMode;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Mcp\Tools\BreakpointTools;
use PhpXdebugMcp\Infrastructure\RealProcessSpawner;
use PhpXdebugMcp\Mcp\Tools\ControlTools;
use PhpXdebugMcp\Mcp\Tools\DockerHelperTools;
use PhpXdebugMcp\Mcp\Tools\HelperTools;
use PhpXdebugMcp\Mcp\Tools\InspectionTools;
use PhpXdebugMcp\Mcp\Tools\IoTools;
use PhpXdebugMcp\Mcp\Tools\SessionTools;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\BreakpointStore;
use PhpXdebugMcp\Services\EventRecorder;
use PhpXdebugMcp\Services\OutputBufferStore;
use PhpXdebugMcp\Services\PathMapper;
use PhpXdebugMcp\Services\ServerInstructionsBuilder;
use PhpXdebugMcp\Services\SessionClaimManager;
use PhpXdebugMcp\Services\SessionRegistry;
use Psr\Log\LoggerInterface;

/**
 * Wires the entire adapter together and produces the ready-to-listen
 * php-mcp/server Server instance. The MCP tool surface is registered
 * statically via withTool/withResource — manual registrations override any
 * attribute-based ones, which keeps tool visibility exactly aligned with the
 * configured safety mode.
 *
 * @phpstan-type Wired array{server:Server, runtime:DbgpRuntime, listener:DbgpListener, logger:LoggerInterface}
 */
final class ServerFactory
{
    /** @return Wired */
    public static function build(Config $cfg): array
    {
        $logger = new StderrLogger($cfg->logPath, $cfg->logLevel);
        $clock = new SystemClock();
        $audit = new AuditLogger($logger);

        $registry = new SessionRegistry();
        $claims = new SessionClaimManager($clock, autoClaimSingleClient: true);
        $store = new BreakpointStore();
        $events = new EventRecorder($clock);
        $output = new OutputBufferStore($clock);
        $pathMapper = new PathMapper($cfg->pathRules);

        $listener = new DbgpListener(
            host: $cfg->listenHost,
            port: $cfg->listenPort,
            logger: $logger,
            allowNonLoopback: $cfg->listenHost !== '127.0.0.1' && $cfg->listenHost !== '::1',
            allowedClientIps: $cfg->allowedClientIps,
        );

        $runtime = new DbgpRuntime(
            listener: $listener,
            registry: $registry,
            breakpoints: $store,
            events: $events,
            output: $output,
            pathMapper: $pathMapper,
            audit: $audit,
            logger: $logger,
            clock: $clock,
            maxChildren: $cfg->defaultMaxChildren,
            maxData: $cfg->defaultMaxData,
            maxDepth: $cfg->defaultMaxDepth,
            workspaceRoots: $cfg->workspaceRoots,
        );

        $resolver = new SessionResolver($registry, $claims);

        $sessionTools = new SessionTools($runtime, $listener, $registry, $claims, $resolver, $audit, $cfg);
        $controlTools = new ControlTools($runtime, $resolver, $claims, $audit, $cfg);
        $bpTools = new BreakpointTools($store, $runtime, $registry, $resolver, $claims, $pathMapper, $clock, $audit);
        $inspectTools = new InspectionTools($runtime, $resolver, $claims, $pathMapper, $audit, $cfg->safetyMode, $cfg->inspectionTimeoutMs);
        $ioTools = new IoTools($runtime, $resolver, $claims, $output, $audit, $cfg->safetyMode);
        $helperTools = new HelperTools($cfg, $audit);
        $dockerHelpers = $cfg->dockerHelpersEnabled
            ? new DockerHelperTools($cfg, new RealProcessSpawner(), $audit)
            : null;
        $resources = new SessionResources($registry, $store, $events, $output, $claims, $pathMapper);

        $instructions = (new ServerInstructionsBuilder())->build($cfg);

        $builder = Server::make()
            ->withServerInfo($cfg->serverName, $cfg->serverVersion)
            ->withInstructions($instructions)
            ->withLogger($logger)
            ->withCapabilities(ServerCapabilities::make(
                resources: true,
                resourcesSubscribe: false,
                prompts: false,
                tools: true,
            ));

        self::registerSessionTools($builder, $sessionTools);
        self::registerControlTools($builder, $controlTools, $cfg);
        self::registerBreakpointTools($builder, $bpTools, $cfg);
        self::registerInspectionTools($builder, $inspectTools, $cfg);
        self::registerIoTools($builder, $ioTools, $cfg);
        self::registerHelperTools($builder, $helperTools);
        if ($dockerHelpers !== null) {
            self::registerDockerHelperTools($builder, $dockerHelpers);
        }
        self::registerResources($builder, $resources);

        $server = $builder->build();

        return [
            'server' => $server,
            'runtime' => $runtime,
            'listener' => $listener,
            'logger' => $logger,
        ];
    }

    private static function registerSessionTools(ServerBuilder $b, SessionTools $t): void
    {
        $b->withTool($t->serverStatus(...), name: 'xdebug_server_status', description: 'Listener address, attached sessions, current safety mode and allow_stop/allow_detach flags.');
        $b->withTool($t->listSessions(...), name: 'xdebug_list_sessions', description: 'List all live and recently terminated debug sessions.');
        $b->withTool($t->waitForSession(...), name: 'xdebug_wait_for_session', description: 'Block up to timeout_ms ms for an Xdebug session to attach (or for any session to reach expected_state).');
        $b->withTool($t->getSession(...), name: 'xdebug_get_session', description: 'Snapshot of one session (auto-resolves to the only claimed session when session_id is omitted).');
        $b->withTool($t->claimSession(...), name: 'xdebug_claim_session', description: 'Take exclusive control of a session. Required before mutating tools.');
        $b->withTool($t->releaseSession(...), name: 'xdebug_release_session', description: 'Release the claim on a session.');
    }

    private static function registerControlTools(ServerBuilder $b, ControlTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool($t->continue(...), name: 'xdebug_continue', description: 'Continue execution until the next breakpoint or end of script.');
        $b->withTool($t->stepInto(...), name: 'xdebug_step_into', description: 'Step into the next statement; descend into function calls.');
        $b->withTool($t->stepOver(...), name: 'xdebug_step_over', description: 'Step over the next statement; do not descend into function calls.');
        $b->withTool($t->stepOut(...), name: 'xdebug_step_out', description: 'Run until the current function returns.');
        $b->withTool($t->breakExecution(...), name: 'xdebug_break_execution', description: 'Interrupt a running session (requires supports_async).');
        $b->withTool($t->waitForState(...), name: 'xdebug_wait_for_state', description: 'Block up to timeout_ms until the session reaches expected_state.');
        if ($cfg->allowStop) {
            $b->withTool($t->stop(...), name: 'xdebug_stop', description: 'Terminate the script via DBGp stop. The socket is expected to close.');
        }
        if ($cfg->allowDetach) {
            $b->withTool($t->detach(...), name: 'xdebug_detach', description: 'Detach the debugger; the script continues without DBGp.');
        }
    }

    private static function registerBreakpointTools(ServerBuilder $b, BreakpointTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool($t->setBreakpoint(...), name: 'xdebug_set_breakpoint', description: 'Add a breakpoint. type=line|conditional|exception|call|return|watch. file_path is a local workspace path; the adapter maps it to the runtime URI.');
        $b->withTool($t->listBreakpoints(...), name: 'xdebug_list_breakpoints', description: 'List adapter-registered breakpoints (and engine bindings if a session is given).');
        $b->withTool($t->updateBreakpoint(...), name: 'xdebug_update_breakpoint', description: 'Patch fields of an existing breakpoint. Patch keys: enabled, lineno, hit_value, hit_condition.');
        $b->withTool($t->removeBreakpoint(...), name: 'xdebug_remove_breakpoint', description: 'Remove a breakpoint and uninstall it from all live sessions.');
        $b->withTool($t->runToCursor(...), name: 'xdebug_run_to_cursor', description: 'Install a temporary line breakpoint and prompt the agent to call xdebug_continue.');
    }

    private static function registerInspectionTools(ServerBuilder $b, InspectionTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool($t->getStack(...), name: 'xdebug_get_stack', description: 'Read the current stack frames; each frame is normalised with mapping_status (file/eval/internal/unknown).');
        $b->withTool($t->getContexts(...), name: 'xdebug_get_contexts', description: 'Available variable contexts at a given stack depth.');
        $b->withTool($t->getVariables(...), name: 'xdebug_get_variables', description: 'List variables in a given context, with type and value (capped by max_data).');
        $b->withTool($t->getProperty(...), name: 'xdebug_get_property', description: 'Fetch a single property by fullname; supports paging.');
        $b->withTool($t->getSource(...), name: 'xdebug_get_source', description: 'Retrieve source for a file at the runtime path; respects begin/end line range.');
        $b->withTool($t->getExecutableLines(...), name: 'xdebug_get_executable_lines', description: 'Xdebug-specific: return the set of lines that may carry breakpoints in a given file.');
        $b->withTool($t->getTypemap(...), name: 'xdebug_get_typemap', description: 'DBGp typemap for the current session.');
        if ($cfg->safetyMode === SafetyMode::FullControl) {
            $b->withTool($t->eval(...), name: 'xdebug_eval', description: 'Evaluate a PHP expression in the current scope (full_control only).');
            $b->withTool($t->setProperty(...), name: 'xdebug_set_property', description: 'Mutate a property by fullname (full_control only).');
        }
    }

    private static function registerIoTools(ServerBuilder $b, IoTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool($t->configureOutput(...), name: 'xdebug_configure_output', description: 'Configure stdout/stderr capture (0=disable, 1=copy, 2=redirect).');
        if ($cfg->safetyMode === SafetyMode::FullControl) {
            $b->withTool($t->sendStdin(...), name: 'xdebug_send_stdin', description: 'Push bytes onto the engine\'s stdin (full_control only).');
        }
    }

    private static function registerHelperTools(ServerBuilder $b, HelperTools $t): void
    {
        $b->withTool($t->runCli(...), name: 'php_debug_run_cli', description: 'Spawn a PHP CLI script with XDEBUG_TRIGGER=1 pointing at this adapter.');
        $b->withTool($t->httpRequest(...), name: 'php_debug_http_request', description: 'Fire an HTTP request with the XDEBUG_SESSION cookie to start a debug session.');
    }

    private static function registerDockerHelperTools(ServerBuilder $b, DockerHelperTools $t): void
    {
        $b->withTool($t->dockerExec(...), name: 'php_debug_docker_exec', description: 'Spawn a PHP CLI script inside a running container or compose service via docker exec, with XDEBUG_TRIGGER + XDEBUG_CONFIG (host.docker.internal) injected. use_compose=true switches to docker compose exec.');
        $b->withTool($t->dockerRun(...), name: 'php_debug_docker_run', description: 'Run an ephemeral container (docker run --rm) with XDEBUG env injected and host-gateway add-host wired by default. Optional network parameter joins a compose network so the container can reach upstream services.');
    }

    private static function registerResources(ServerBuilder $b, SessionResources $r): void
    {
        $b->withResource($r->listSessions(...), uri: 'xdebug://sessions', mimeType: 'application/json');
        $b->withResourceTemplate($r->session(...), uriTemplate: 'xdebug://session/{sessionId}', mimeType: 'application/json');
        $b->withResourceTemplate($r->stack(...), uriTemplate: 'xdebug://session/{sessionId}/stack', mimeType: 'application/json');
        $b->withResourceTemplate($r->breakpoints(...), uriTemplate: 'xdebug://session/{sessionId}/breakpoints', mimeType: 'application/json');
        $b->withResourceTemplate($r->events(...), uriTemplate: 'xdebug://session/{sessionId}/events', mimeType: 'application/json');
        $b->withResourceTemplate($r->stdout(...), uriTemplate: 'xdebug://session/{sessionId}/stdout', mimeType: 'text/plain');
        $b->withResourceTemplate($r->stderr(...), uriTemplate: 'xdebug://session/{sessionId}/stderr', mimeType: 'text/plain');
        $b->withResourceTemplate($r->source(...), uriTemplate: 'xdebug://session/{sessionId}/source/{filepath}', mimeType: 'application/json');
    }
}
