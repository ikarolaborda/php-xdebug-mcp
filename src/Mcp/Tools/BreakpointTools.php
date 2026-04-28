<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Mcp\Tools;

use PhpXdebugMcp\Dbgp\DbgpRuntime;
use PhpXdebugMcp\Dbgp\DbgpSession;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointDefinition;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointScope;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointType;
use PhpXdebugMcp\Domain\Breakpoints\HitCondition;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Infrastructure\Clock;
use PhpXdebugMcp\Mcp\SessionResolver;
use PhpXdebugMcp\Mcp\ToolResult;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\BreakpointStore;
use PhpXdebugMcp\Services\PathMapper;
use PhpXdebugMcp\Services\SessionClaimManager;
use PhpXdebugMcp\Services\SessionRegistry;

/**
 * Adapter-centric breakpoint management. Persistent breakpoints are stored
 * once and replayed into every new matching session.
 */
final class BreakpointTools
{
    public function __construct(
        private readonly BreakpointStore $store,
        private readonly DbgpRuntime $runtime,
        private readonly SessionRegistry $registry,
        private readonly SessionResolver $resolver,
        private readonly SessionClaimManager $claims,
        private readonly PathMapper $pathMapper,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Set or update a persistent or session-scoped breakpoint.
     *
     * @param array<string, mixed>|null $hit_condition
     */
    public function setBreakpoint(
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
    ): array {
        try {
            $bpType = BreakpointType::from($type);
            $bpScope = BreakpointScope::from($scope);
            $now = $this->clock->nowIso8601();

            $remoteUri = null;
            if ($file_path !== null && $bpType->requiresFile()) {
                $remoteUri = $this->pathMapper->toRemoteUri($file_path)->remoteUri;
            }

            $def = BreakpointDefinition::create(
                type: $bpType,
                scope: $bpScope,
                enabled: $enabled,
                temporary: $temporary,
                localPath: $file_path,
                remoteUri: $remoteUri,
                lineno: $lineno,
                functionName: $function,
                exceptionName: $exception,
                expression: $expression,
                hitValue: $hit_value,
                hitCondition: $hit_condition !== null ? HitCondition::from($hit_condition) : null,
                now: $now,
            );

            if ($bpScope === BreakpointScope::Persistent) {
                $this->store->addPersistent($def);
                foreach ($this->registry->all() as $session) {
                    self::checkTypeAdvertised($session->breakpointTypes, $def->type);
                    $this->runtime->installBreakpoint($session, $def);
                }
            } else {
                $session = $this->resolver->resolve($session_id);
                $this->claims->requireClaimed($session->adapterId);
                self::checkTypeAdvertised($session->breakpointTypes, $def->type);
                $this->store->addSessionScoped($session->adapterId, $def);
                $this->runtime->installBreakpoint($session, $def);
            }
            $this->audit->tool('xdebug_set_breakpoint', null, ['type' => $type, 'scope' => $scope], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok(
            'Breakpoint ' . $def->adapterId . ' registered.',
            ['breakpoint' => $def->toArray()],
        );
    }

    public function listBreakpoints(?string $session_id = null): array
    {
        try {
            $session = $session_id !== null ? $this->registry->tryGet($session_id) : null;
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }
        $defs = $session !== null ? $this->store->forSession($session->adapterId) : $this->store->persistent();
        $out = [];
        foreach ($defs as $d) {
            $row = $d->toArray();
            if ($session !== null) {
                $row['engine_id'] = $session->bpBindings[$d->adapterId] ?? null;
            }
            $out[] = $row;
        }

        return ToolResult::ok(count($out) . ' breakpoint(s).', ['breakpoints' => $out], $session);
    }

    public function updateBreakpoint(string $adapter_id, array $patch = []): array
    {
        try {
            $existing = $this->store->get($adapter_id);
            $next = $existing->applyPatch($patch, $this->clock->nowIso8601());
            $this->store->update($adapter_id, $next);
            foreach ($this->registry->all() as $session) {
                $engineId = $session->bpBindings[$adapter_id] ?? null;
                if ($engineId === null) {
                    continue;
                }
                $args = ['d' => $engineId];
                if (array_key_exists('lineno', $patch)) {
                    $args['n'] = $next->lineno;
                }
                if (array_key_exists('enabled', $patch)) {
                    $args['s'] = $next->enabled ? 'enabled' : 'disabled';
                }
                if (array_key_exists('hit_value', $patch)) {
                    $args['h'] = $next->hitValue;
                }
                if (array_key_exists('hit_condition', $patch)) {
                    $args['o'] = $next->hitCondition?->value;
                }
                $session->sendCommand(
                    'breakpoint_update',
                    $args,
                    null,
                    isContinuation: false,
                    isBreak: false,
                    resolver: static function () {},
                );
                $this->runtime->tickUntil(static fn (): bool => $session->inFlight() === null, 500);
            }
            $this->audit->tool('xdebug_update_breakpoint', null, ['adapter_id' => $adapter_id], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok('Breakpoint updated.', ['breakpoint' => $next->toArray()]);
    }

    public function removeBreakpoint(string $adapter_id): array
    {
        try {
            $def = $this->store->remove($adapter_id);
            foreach ($this->registry->all() as $session) {
                $this->runtime->removeBreakpoint($session, $adapter_id);
            }
            $this->audit->tool('xdebug_remove_breakpoint', null, ['adapter_id' => $adapter_id], 'ok');
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        return ToolResult::ok('Breakpoint removed.', ['breakpoint' => $def->toArray()]);
    }

    /**
     * @param list<string> $advertised
     */
    private static function checkTypeAdvertised(array $advertised, BreakpointType $type): void
    {
        if ($advertised === []) {
            return;
        }
        if (in_array($type->value, $advertised, true)) {
            return;
        }
        throw \PhpXdebugMcp\Domain\Errors\AdapterException::from(
            \PhpXdebugMcp\Domain\Errors\AdapterErrorCode::FeatureUnsupported,
            'Engine does not advertise breakpoint type ' . $type->value . '. Advertised: ' . implode(', ', $advertised),
            ['hint' => 'Choose one of the advertised types or use xdebug_run_to_cursor for line breakpoints.'],
        );
    }

    public function runToCursor(string $file_path, int $lineno, ?string $session_id = null, int $timeout_ms = 30000): array
    {
        try {
            $session = $this->resolver->resolve($session_id);
            $this->claims->requireClaimed($session->adapterId);
            $remoteUri = $this->pathMapper->toRemoteUri($file_path)->remoteUri;
            $now = $this->clock->nowIso8601();
            $def = BreakpointDefinition::create(
                type: BreakpointType::Line,
                scope: BreakpointScope::Session,
                enabled: true,
                temporary: true,
                localPath: $file_path,
                remoteUri: $remoteUri,
                lineno: $lineno,
                functionName: null,
                exceptionName: null,
                expression: null,
                hitValue: null,
                hitCondition: null,
                now: $now,
            );
            $this->store->addSessionScoped($session->adapterId, $def);
            $this->runtime->installBreakpoint($session, $def);
        } catch (AdapterException $e) {
            return ToolResult::error($e);
        }

        /*
         * Surface the follow-up via next_actions instead of chaining a
         * continue inline: the agent owns the timing, and the tool
         * envelope stays narrowly scoped to the breakpoint install.
         */
        return ToolResult::ok(
            'Temporary breakpoint installed; call xdebug_continue to run to that line.',
            ['breakpoint' => $def->toArray()],
            $session,
            nextActions: ['Call xdebug_continue with timeout_ms=' . $timeout_ms],
        );
    }
}
