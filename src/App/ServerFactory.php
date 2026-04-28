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
        $b->withTool(static fn (): array => $t->serverStatus(), name: 'xdebug_server_status', description: 'Listener address, attached sessions, current safety mode and allow_stop/allow_detach flags.');
        $b->withTool(static fn (): array => $t->listSessions(), name: 'xdebug_list_sessions', description: 'List all live and recently terminated debug sessions.');
        $b->withTool(static fn (int $timeout_ms = 30000, ?string $expected_state = null): array => $t->waitForSession($timeout_ms, $expected_state), name: 'xdebug_wait_for_session', description: 'Block up to timeout_ms ms for an Xdebug session to attach (or for any session to reach expected_state).');
        $b->withTool(static fn (?string $session_id = null): array => $t->getSession($session_id), name: 'xdebug_get_session', description: 'Snapshot of one session (auto-resolves to the only claimed session when session_id is omitted).');
        $b->withTool(static fn (?string $session_id = null, ?string $client_id = null): array => $t->claimSession($session_id, $client_id), name: 'xdebug_claim_session', description: 'Take exclusive control of a session. Required before mutating tools.');
        $b->withTool(static fn (?string $session_id = null): array => $t->releaseSession($session_id), name: 'xdebug_release_session', description: 'Release the claim on a session.');
    }

    private static function registerControlTools(ServerBuilder $b, ControlTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool(static fn (?string $session_id = null, ?int $timeout_ms = null): array => $t->continue($session_id, $timeout_ms), name: 'xdebug_continue', description: 'Continue execution until the next breakpoint or end of script.');
        $b->withTool(static fn (?string $session_id = null, ?int $timeout_ms = null): array => $t->stepInto($session_id, $timeout_ms), name: 'xdebug_step_into', description: 'Step into the next statement; descend into function calls.');
        $b->withTool(static fn (?string $session_id = null, ?int $timeout_ms = null): array => $t->stepOver($session_id, $timeout_ms), name: 'xdebug_step_over', description: 'Step over the next statement; do not descend into function calls.');
        $b->withTool(static fn (?string $session_id = null, ?int $timeout_ms = null): array => $t->stepOut($session_id, $timeout_ms), name: 'xdebug_step_out', description: 'Run until the current function returns.');
        $b->withTool(static fn (?string $session_id = null, int $timeout_ms = 5000): array => $t->breakExecution($session_id, $timeout_ms), name: 'xdebug_break_execution', description: 'Interrupt a running session (requires supports_async).');
        $b->withTool(static fn (?string $session_id = null, string $expected_state = 'break', int $timeout_ms = 30000): array => $t->waitForState($session_id, $expected_state, $timeout_ms), name: 'xdebug_wait_for_state', description: 'Block up to timeout_ms until the session reaches expected_state.');
        if ($cfg->allowStop) {
            $b->withTool(static fn (?string $session_id = null): array => $t->stop($session_id), name: 'xdebug_stop', description: 'Terminate the script via DBGp stop. The socket is expected to close.');
        }
        if ($cfg->allowDetach) {
            $b->withTool(static fn (?string $session_id = null): array => $t->detach($session_id), name: 'xdebug_detach', description: 'Detach the debugger; the script continues without DBGp.');
        }
    }

    private static function registerBreakpointTools(ServerBuilder $b, BreakpointTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool(
            static fn (
                string $type = 'line',
                ?string $file_path = null,
                ?int $lineno = null,
                ?string $function = null,
                ?string $exception = null,
                ?string $expression = null,
                bool $enabled = true,
                bool $temporary = false,
                ?int $hit_value = null,
                ?string $hit_condition = null,
                string $scope = 'persistent',
                ?string $session_id = null,
            ): array => $t->setBreakpoint($type, $file_path, $lineno, $function, $exception, $expression, $enabled, $temporary, $hit_value, $hit_condition, $scope, $session_id),
            name: 'xdebug_set_breakpoint',
            description: 'Add a breakpoint. type=line|conditional|exception|call|return|watch. file_path is a local workspace path; the adapter maps it to the runtime URI.'
        );
        $b->withTool(static fn (?string $session_id = null): array => $t->listBreakpoints($session_id), name: 'xdebug_list_breakpoints', description: 'List adapter-registered breakpoints (and engine bindings if a session is given).');
        $b->withTool(static fn (string $adapter_id, array $patch = []): array => $t->updateBreakpoint($adapter_id, $patch), name: 'xdebug_update_breakpoint', description: 'Patch fields of an existing breakpoint. Patch keys: enabled, lineno, hit_value, hit_condition.');
        $b->withTool(static fn (string $adapter_id): array => $t->removeBreakpoint($adapter_id), name: 'xdebug_remove_breakpoint', description: 'Remove a breakpoint and uninstall it from all live sessions.');
        $b->withTool(static fn (string $file_path, int $lineno, ?string $session_id = null, int $timeout_ms = 30000): array => $t->runToCursor($file_path, $lineno, $session_id, $timeout_ms), name: 'xdebug_run_to_cursor', description: 'Install a temporary line breakpoint and prompt the agent to call xdebug_continue.');
    }

    private static function registerInspectionTools(ServerBuilder $b, InspectionTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool(static fn (?string $session_id = null, ?int $depth = null): array => $t->getStack($session_id, $depth), name: 'xdebug_get_stack', description: 'Read the current stack frames; each frame is normalised with mapping_status (file/eval/internal/unknown).');
        $b->withTool(static fn (?string $session_id = null, int $depth = 0): array => $t->getContexts($session_id, $depth), name: 'xdebug_get_contexts', description: 'Available variable contexts at a given stack depth.');
        $b->withTool(static fn (?string $session_id = null, int $depth = 0, int $context = 0): array => $t->getVariables($session_id, $depth, $context), name: 'xdebug_get_variables', description: 'List variables in a given context, with type and value (capped by max_data).');
        $b->withTool(static fn (string $name, ?string $session_id = null, int $depth = 0, int $context = 0, ?int $page = null, ?int $max_data = null): array => $t->getProperty($name, $session_id, $depth, $context, $page, $max_data), name: 'xdebug_get_property', description: 'Fetch a single property by fullname; supports paging.');
        $b->withTool(static fn (string $file_path, ?int $begin = null, ?int $end = null, ?string $session_id = null): array => $t->getSource($file_path, $begin, $end, $session_id), name: 'xdebug_get_source', description: 'Retrieve source for a file at the runtime path; respects begin/end line range.');
        $b->withTool(static fn (string $file_path, ?string $session_id = null): array => $t->getExecutableLines($file_path, $session_id), name: 'xdebug_get_executable_lines', description: 'Xdebug-specific: return the set of lines that may carry breakpoints in a given file.');
        $b->withTool(static fn (?string $session_id = null): array => $t->getTypemap($session_id), name: 'xdebug_get_typemap', description: 'DBGp typemap for the current session.');
        if ($cfg->safetyMode === SafetyMode::FullControl) {
            $b->withTool(static fn (string $code, ?string $session_id = null, ?int $page = null): array => $t->eval($code, $session_id, $page), name: 'xdebug_eval', description: 'Evaluate a PHP expression in the current scope (full_control only).');
            $b->withTool(static fn (string $name, string $value, ?string $type = null, ?string $session_id = null, int $depth = 0, int $context = 0): array => $t->setProperty($name, $value, $type, $session_id, $depth, $context), name: 'xdebug_set_property', description: 'Mutate a property by fullname (full_control only).');
        }
    }

    private static function registerIoTools(ServerBuilder $b, IoTools $t, Config $cfg): void
    {
        if ($cfg->safetyMode === SafetyMode::Observe) {
            return;
        }
        $b->withTool(static fn (string $stdout = '1', string $stderr = '1', ?string $session_id = null): array => $t->configureOutput($stdout, $stderr, $session_id), name: 'xdebug_configure_output', description: 'Configure stdout/stderr capture (0=disable, 1=copy, 2=redirect).');
        if ($cfg->safetyMode === SafetyMode::FullControl) {
            $b->withTool(static fn (string $data, ?string $session_id = null): array => $t->sendStdin($data, $session_id), name: 'xdebug_send_stdin', description: 'Push bytes onto the engine\'s stdin (full_control only).');
        }
    }

    private static function registerHelperTools(ServerBuilder $b, HelperTools $t): void
    {
        $b->withTool(static fn (string $script, array $args = [], ?string $php_binary = null, array $env_overrides = []): array => $t->runCli($script, $args, $php_binary, $env_overrides), name: 'php_debug_run_cli', description: 'Spawn a PHP CLI script with XDEBUG_TRIGGER=1 pointing at this adapter.');
        $b->withTool(static fn (string $url, string $method = 'GET', array $headers = [], ?string $body = null, string $cookie_name = 'XDEBUG_SESSION', string $cookie_value = 'mcp'): array => $t->httpRequest($url, $method, $headers, $body, $cookie_name, $cookie_value), name: 'php_debug_http_request', description: 'Fire an HTTP request with the XDEBUG_SESSION cookie to start a debug session.');
    }

    private static function registerDockerHelperTools(ServerBuilder $b, DockerHelperTools $t): void
    {
        $b->withTool(
            static fn (
                string $container_or_service,
                string $script,
                array $args = [],
                ?string $php_binary = null,
                array $env_overrides = [],
                bool $use_compose = false,
                ?string $working_dir = null,
                ?string $user = null,
            ): array => $t->dockerExec($container_or_service, $script, $args, $php_binary, $env_overrides, $use_compose, $working_dir, $user),
            name: 'php_debug_docker_exec',
            description: 'Spawn a PHP CLI script inside a running container or compose service via docker exec, with XDEBUG_TRIGGER + XDEBUG_CONFIG (host.docker.internal) injected. use_compose=true switches to docker compose exec.'
        );
        $b->withTool(
            static fn (
                string $image,
                array $command = [],
                array $env_overrides = [],
                array $volumes = [],
                array $extra_hosts = [],
                ?string $working_dir = null,
                ?string $network = null,
            ): array => $t->dockerRun($image, $command, $env_overrides, $volumes, $extra_hosts, $working_dir, $network),
            name: 'php_debug_docker_run',
            description: 'Run an ephemeral container (docker run --rm) with XDEBUG env injected and host-gateway add-host wired by default. Optional network parameter joins a compose network so the container can reach upstream services.'
        );
    }

    private static function registerResources(ServerBuilder $b, SessionResources $r): void
    {
        $b->withResource(static fn (): array => $r->listSessions(), uri: 'xdebug://sessions', mimeType: 'application/json');
        $b->withResourceTemplate(static fn (string $sessionId): array => $r->session($sessionId), uriTemplate: 'xdebug://session/{sessionId}', mimeType: 'application/json');
        $b->withResourceTemplate(static fn (string $sessionId): array => $r->stack($sessionId), uriTemplate: 'xdebug://session/{sessionId}/stack', mimeType: 'application/json');
        $b->withResourceTemplate(static fn (string $sessionId): array => $r->breakpoints($sessionId), uriTemplate: 'xdebug://session/{sessionId}/breakpoints', mimeType: 'application/json');
        $b->withResourceTemplate(static fn (string $sessionId): array => $r->events($sessionId), uriTemplate: 'xdebug://session/{sessionId}/events', mimeType: 'application/json');
        $b->withResourceTemplate(static fn (string $sessionId): array => $r->stdout($sessionId), uriTemplate: 'xdebug://session/{sessionId}/stdout', mimeType: 'text/plain');
        $b->withResourceTemplate(static fn (string $sessionId): array => $r->stderr($sessionId), uriTemplate: 'xdebug://session/{sessionId}/stderr', mimeType: 'text/plain');
        $b->withResourceTemplate(static fn (string $sessionId, string $filepath): array => $r->source($sessionId, $filepath), uriTemplate: 'xdebug://session/{sessionId}/source/{filepath}', mimeType: 'application/json');
    }
}
