<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

use PhpXdebugMcp\Domain\Breakpoints\BreakpointDefinition;
use PhpXdebugMcp\Domain\Breakpoints\BreakpointType;
use PhpXdebugMcp\Domain\Diagnostics\SessionWarning;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Events\EventKind;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use PhpXdebugMcp\Infrastructure\Clock;
use PhpXdebugMcp\Services\AuditLogger;
use PhpXdebugMcp\Services\BreakpointStore;
use PhpXdebugMcp\Services\EventRecorder;
use PhpXdebugMcp\Services\OutputBufferStore;
use PhpXdebugMcp\Services\PathMapper;
use PhpXdebugMcp\Services\SessionRegistry;
use Psr\Log\LoggerInterface;

/**
 * The "always-on" DBGp pump.
 *
 * - Owns the listener and per-session sockets.
 * - tick() is non-blocking; returns immediately after one stream_select pass.
 * - tickUntil(predicate, deadlineMs) drives the pump synchronously while a
 *   tool waits for a state change. The MCP transport is single-threaded so
 *   stdin handling pauses while a tool runs anyway, which is intentional.
 * - Resolves engine responses to in-flight commands, dispatches notifications
 *   and stream packets to the recorders, transitions session state.
 */
final class DbgpRuntime
{
    /**
     * @var array{
     *     max_children:int,
     *     max_data:int,
     *     max_depth:int
     * }
     */
    private array $featureOverrides;

    /** @var list<string> */
    private array $workspaceRoots;

    /**
     * @param list<string> $workspaceRoots
     */
    public function __construct(
        private readonly DbgpListener $listener,
        private readonly SessionRegistry $registry,
        private readonly BreakpointStore $breakpoints,
        private readonly EventRecorder $events,
        private readonly OutputBufferStore $output,
        private readonly PathMapper $pathMapper,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        int $maxChildren,
        int $maxData,
        int $maxDepth,
        array $workspaceRoots = [],
    ) {
        $this->featureOverrides = [
            'max_children' => $maxChildren,
            'max_data' => $maxData,
            'max_depth' => $maxDepth,
        ];
        $this->workspaceRoots = $workspaceRoots;
    }

    public function start(): void
    {
        $this->listener->start();
    }

    /**
     * Single non-blocking pass: accept new connections, read/process pending
     * data on every session, install missing persistent breakpoints, etc.
     *
     * @return int number of bytes/packets visibly processed (used by waiters as a heartbeat)
     */
    public function tick(int $timeoutMicros = 0): int
    {
        $progress = 0;

        foreach ($this->listener->acceptPending() as $accepted) {
            $session = new DbgpSession($accepted['socket'], $accepted['peer'], $this->clock, $this->logger);
            $this->registry->add($session);
            $this->events->record($session->adapterId, EventKind::SessionCreated, ['peer' => $accepted['peer']]);
            $this->logger->info('dbgp.session.connected', ['session' => $session->adapterId, 'peer' => $accepted['peer']]);
            $progress++;
        }

        $sessions = $this->registry->all();
        if ($sessions === []) {
            if ($timeoutMicros > 0) {
                usleep($timeoutMicros);
            }

            return $progress;
        }

        $read = [];
        foreach ($sessions as $s) {
            if (is_resource($s->socket)) {
                $read[] = $s->socket;
            }
        }
        if ($read === []) {
            return $progress;
        }
        $write = null;
        $except = null;
        $tvSec = (int) ($timeoutMicros / 1_000_000);
        $tvUsec = $timeoutMicros % 1_000_000;
        $ready = @stream_select($read, $write, $except, $tvSec, $tvUsec);
        if ($ready === false || $ready === 0) {
            return $progress;
        }

        foreach ($sessions as $session) {
            if (!in_array($session->socket, $read, true)) {
                continue;
            }
            $session->readPending();
            foreach ($session->drainPackets() as $packet) {
                $this->handlePacket($session, $packet);
                $progress++;
            }
        }

        return $progress;
    }

    /**
     * Drive the pump until the predicate returns true or we hit the deadline.
     * Returns true if the predicate fired, false if we timed out.
     */
    public function tickUntil(callable $predicate, int $deadlineMs): bool
    {
        $start = (int) (microtime(true) * 1000);
        $deadline = $start + max(0, $deadlineMs);
        if ($predicate()) {
            return true;
        }
        while (true) {
            $now = (int) (microtime(true) * 1000);
            $remaining = $deadline - $now;
            if ($remaining <= 0) {
                return $predicate();
            }
            $slice = (int) min(50_000, $remaining * 1000);
            $this->tick($slice);
            if ($predicate()) {
                return true;
            }
        }
    }

    private function handlePacket(DbgpSession $session, array $packet): void
    {
        $kind = $packet['kind'];

        if ($kind === ResponseMapper::PACKET_INIT) {
            $session->init = $packet['data'];
            $this->onInit($session);

            return;
        }

        if ($kind === ResponseMapper::PACKET_RESPONSE) {
            $session->applyResponseStateTransition($packet['data']);
            $session->deliverResponse($packet['data']);
            $this->events->record($session->adapterId, EventKind::CommandReplied, [
                'command' => $packet['data']['command'] ?? null,
                'txid' => $packet['data']['transaction_id'] ?? null,
                'status' => $packet['data']['status'] ?? null,
            ]);

            return;
        }

        if ($kind === ResponseMapper::PACKET_NOTIFY) {
            $this->onNotify($session, $packet['data']);

            return;
        }

        if ($kind === ResponseMapper::PACKET_STREAM) {
            $this->output->append($session->adapterId, $packet['data']['type'] ?? 'stdout', $packet['data']['body'] ?? '');
            $this->events->record($session->adapterId, EventKind::StreamData, [
                'stream' => $packet['data']['type'] ?? 'stdout',
                'bytes' => strlen($packet['data']['body'] ?? ''),
            ]);
        }
    }

    private function onInit(DbgpSession $session): void
    {
        // Probe features synchronously by sending feature_get for each known name.
        // Each probe enqueues using the normal slot. To keep things simple we
        // serialise the negotiation on first init with short waits.
        foreach (FeatureNegotiator::probeList() as $name) {
            $session->sendCommand(
                'feature_get',
                ['n' => $name],
                null,
                isContinuation: false,
                isBreak: false,
                resolver: function (array $response) use ($session, $name): void {
                    $value = '';
                    foreach ($response['children'] ?? [] as $c) {
                        $value = (string) ($c['value'] ?? '');
                    }
                    $value = $value !== '' ? $value : (string) ($response['attrs']['supported'] ?? '0');
                    $session->features[$name] = $value;
                    if ($name === 'supports_async') {
                        $session->supportsAsync = $value === '1';
                    }
                    if ($name === 'resolved_breakpoints') {
                        $session->supportsResolved = $value === '1';
                    }
                    if ($name === 'notify_ok') {
                        $session->supportsNotify = $value === '1';
                    }
                    if ($name === 'extended_properties') {
                        $session->supportsExtendedProperties = $value === '1';
                    }
                    if ($name === 'breakpoint_types') {
                        $session->breakpointTypes = preg_split('/\s+/', trim($value)) ?: [];
                    }
                },
            );
            $this->tickUntil(static fn (): bool => $session->inFlight() === null, 1000);
        }

        foreach (FeatureNegotiator::preferredSettings($this->featureOverrides) as $name => $value) {
            try {
                $session->sendCommand(
                    'feature_set',
                    ['n' => $name, 'v' => $value],
                    null,
                    isContinuation: false,
                    isBreak: false,
                    resolver: static function () {},
                );
                $this->tickUntil(static fn (): bool => $session->inFlight() === null, 500);
            } catch (AdapterException $e) {
                $this->logger->debug('feature_set.skipped', ['name' => $name, 'error' => $e->getMessage()]);
            }
        }

        // Install all matching persistent breakpoints into the new session.
        foreach ($this->breakpoints->persistent() as $def) {
            $this->installBreakpoint($session, $def);
        }

        $this->emitInversePathDiagnostic($session);
    }

    public function emitInversePathDiagnostic(DbgpSession $session): void
    {
        if (!$this->pathMapper->rulesConfigured()) {
            return;
        }
        $fileUri = $session->init?->fileUri ?? '';
        if ($fileUri === '') {
            return;
        }
        if ($this->pathMapper->findRuleForRemote($fileUri) !== null) {
            return;
        }
        $suggestion = $this->pathMapper->suggestLikelyLocalRoot($this->workspaceRoots, $fileUri);
        $context = ['remote_fileuri' => $fileUri];
        if ($suggestion !== null) {
            $context['suggested_rule'] = $suggestion->toArray();
        }
        $hint = $suggestion === null
            ? 'Add a path_rules entry whose remote root contains the engine fileuri shown in context.'
            : 'Try adding a path_rules entry: local=' . $suggestion->localRoot . ', remote=' . $suggestion->remoteRoot;
        $warning = new SessionWarning(
            code: SessionWarning::CODE_PATH_RULE_MISSING,
            message: 'Engine session reports a fileuri (' . $fileUri . ') that no configured path rule covers. Breakpoints by local path will fail to match until a rule is added.',
            context: $context,
            hint: $hint,
        );
        $session->addWarning($warning);
        $this->events->record($session->adapterId, EventKind::PathRuleMissing, $warning->toArray());
        $this->logger->warning('dbgp.session.path_rule_missing', [
            'session' => $session->adapterId,
            'fileuri' => $fileUri,
            'suggestion' => $suggestion?->toArray(),
        ]);
    }

    private function onNotify(DbgpSession $session, array $notify): void
    {
        $name = $notify['name'] ?? '';
        $this->audit->notification($session->adapterId, $name, $notify['attrs'] ?? []);
        $this->events->record($session->adapterId, EventKind::Notification, [
            'name' => $name,
            'attrs' => $notify['attrs'] ?? [],
        ]);

        if ($name === 'breakpoint_resolved') {
            $engineId = (string) ($notify['attrs']['id'] ?? '');
            $resolved = ($notify['attrs']['resolved'] ?? '') === 'resolved';
            foreach ($session->bpBindings as $adapterId => $boundEngineId) {
                if ($boundEngineId === $engineId) {
                    $this->events->record($session->adapterId, EventKind::BreakpointResolved, [
                        'adapter_id' => $adapterId,
                        'engine_id' => $engineId,
                        'resolved' => $resolved,
                    ]);

                    return;
                }
            }
        }
    }

    public function installBreakpoint(DbgpSession $session, BreakpointDefinition $def): void
    {
        if (!$def->enabled) {
            return;
        }
        $type = $def->type;
        if ($type === BreakpointType::Watch && !in_array('watch', $session->breakpointTypes, true)) {
            $this->events->record($session->adapterId, EventKind::BreakpointFailed, [
                'adapter_id' => $def->adapterId,
                'reason' => 'engine does not advertise watch breakpoints',
            ]);

            return;
        }
        if ($type === BreakpointType::Conditional && !in_array('conditional', $session->breakpointTypes, true)) {
            $this->events->record($session->adapterId, EventKind::BreakpointFailed, [
                'adapter_id' => $def->adapterId,
                'reason' => 'engine does not advertise conditional breakpoints',
            ]);

            return;
        }

        $remoteUri = $def->remoteUri;
        if (($type === BreakpointType::Line || $type === BreakpointType::Conditional) && $def->localPath !== null && $remoteUri === null) {
            $remoteUri = $this->pathMapper->toRemoteUri($def->localPath)->remoteUri;
        }

        $args = [
            't' => $type->value,
            's' => $def->enabled ? 'enabled' : 'disabled',
            'r' => $def->temporary ? '1' : '0',
        ];
        if ($remoteUri !== null) {
            $args['f'] = $remoteUri;
        }
        if ($def->lineno !== null) {
            $args['n'] = $def->lineno;
        }
        if ($def->functionName !== null) {
            $args['m'] = $def->functionName;
        }
        if ($def->exceptionName !== null) {
            $args['x'] = $def->exceptionName;
        }
        if ($def->hitValue !== null) {
            $args['h'] = $def->hitValue;
        }
        if ($def->hitCondition !== null) {
            $args['o'] = $def->hitCondition->value;
        }

        $session->sendCommand(
            'breakpoint_set',
            $args,
            $def->expression !== null && $def->expression !== '' ? $def->expression : null,
            isContinuation: false,
            isBreak: false,
            resolver: function (array $response) use ($session, $def): void {
                $engineId = (string) ($response['attrs']['id'] ?? '');
                if ($engineId !== '') {
                    $session->bpBindings[$def->adapterId] = $engineId;
                    $this->events->record($session->adapterId, EventKind::BreakpointInstalled, [
                        'adapter_id' => $def->adapterId,
                        'engine_id' => $engineId,
                        'state' => $response['attrs']['state'] ?? 'enabled',
                        'resolved' => $response['attrs']['resolved'] ?? null,
                    ]);
                } else {
                    $this->events->record($session->adapterId, EventKind::BreakpointFailed, [
                        'adapter_id' => $def->adapterId,
                        'errors' => $response['errors'] ?? [],
                    ]);
                }
            },
        );
        $this->tickUntil(static fn (): bool => $session->inFlight() === null, 500);
    }

    public function removeBreakpoint(DbgpSession $session, string $adapterId): void
    {
        $engineId = $session->bpBindings[$adapterId] ?? null;
        if ($engineId === null) {
            return;
        }
        $session->sendCommand(
            'breakpoint_remove',
            ['d' => $engineId],
            null,
            isContinuation: false,
            isBreak: false,
            resolver: function () use ($session, $adapterId): void {
                unset($session->bpBindings[$adapterId]);
            },
        );
        $this->tickUntil(static fn (): bool => $session->inFlight() === null, 500);
    }

    public function dispose(string $sessionId): void
    {
        $session = $this->registry->tryGet($sessionId);
        if ($session === null) {
            return;
        }
        if (is_resource($session->socket)) {
            @fclose($session->socket);
        }
        $this->registry->remove($sessionId);
        $this->breakpoints->dropSession($sessionId);
        $this->events->record($sessionId, EventKind::SessionTerminated, []);
    }
}
