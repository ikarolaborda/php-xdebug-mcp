<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

use PhpXdebugMcp\Domain\Diagnostics\SessionWarning;
use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Sessions\InitMetadata;
use PhpXdebugMcp\Domain\Sessions\SessionState;
use PhpXdebugMcp\Infrastructure\Clock;
use PhpXdebugMcp\Infrastructure\Ids;
use Psr\Log\LoggerInterface;

/**
 * One Xdebug TCP connection plus protocol state.
 *
 * The session owns:
 *  - the non-blocking socket resource
 *  - a DbgpPacketCodec for incremental decoding
 *  - the command arbiter: at most one normal-slot command in flight, plus
 *    an optional break-slot for async interrupts when supports_async=1
 *  - cached negotiated features
 *  - last <init> metadata
 *  - per-session adapter->engine breakpoint id mapping
 *  - an event sink for the runtime to forward responses/notifications
 */
final class DbgpSession
{
    /** @var resource */
    public $socket;

    public string $adapterId;
    public string $peerAddress;
    public string $createdAt;
    public string $updatedAt;

    public SessionState $state = SessionState::Starting;
    public ?string $lastReason = null;
    public ?InitMetadata $init = null;

    /** @var array<string, string> */
    public array $features = [];

    /** @var list<string> */
    public array $breakpointTypes = [];

    /** @var array<string, string> adapterBreakpointId => engineBreakpointId */
    public array $bpBindings = [];

    public bool $supportsAsync = false;
    public bool $supportsResolved = false;
    public bool $supportsNotify = false;
    public bool $supportsExtendedProperties = false;

    public string $stdoutMode = '0';
    public string $stderrMode = '0';

    /** @var array<string, SessionWarning> keyed by warning dedup key */
    public array $warnings = [];

    private DbgpPacketCodec $codec;
    private CommandEncoder $encoder;
    private ResponseMapper $mapper;

    /**
     * Pending normal-slot command. Null when the slot is free.
     *
     * @var array{txid:int, command:string, started_at:float, is_continuation:bool}|null
     */
    private ?array $inFlight = null;

    /** @var array{txid:int, command:string, started_at:float}|null */
    private ?array $breakInFlight = null;

    /**
     * Map from transaction id to a deferred resolver. The runtime registers a
     * callback when it sends a command; the session calls it back on the
     * matching response.
     *
     * @var array<int, callable(array<string,mixed>):void>
     */
    private array $pendingResolvers = [];

    private int $nextTxId = 1;

    /**
     * @param resource $socket
     */
    public function __construct(
        $socket,
        string $peerAddress,
        Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->socket = $socket;
        $this->peerAddress = $peerAddress;
        $this->adapterId = Ids::adapterSessionId();
        $this->createdAt = $clock->nowIso8601();
        $this->updatedAt = $this->createdAt;

        $this->codec = new DbgpPacketCodec();
        $this->encoder = new CommandEncoder();
        $this->mapper = new ResponseMapper();

        stream_set_blocking($socket, false);
    }

    public function isOpen(): bool
    {
        return is_resource($this->socket) && !feof($this->socket);
    }

    public function addWarning(SessionWarning $warning): void
    {
        $this->warnings[$warning->dedupKey()] = $warning;
    }

    /** @return list<array<string, mixed>> */
    public function warningsToArray(): array
    {
        $out = [];
        foreach ($this->warnings as $w) {
            $out[] = $w->toArray();
        }

        return $out;
    }

    public function nextTransactionId(): int
    {
        return $this->nextTxId++;
    }

    public function readPending(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || $chunk === '') {
            if (feof($this->socket)) {
                $this->markDisconnected();
            }

            return;
        }
        $this->codec->append($chunk);
    }

    /**
     * Drain any complete packets that have been read so far. Returns a list
     * of structured packet records: ['kind'=>'init|response|notify|stream',
     * 'data'=>... ].
     *
     * @return list<array{kind:string, data:mixed}>
     */
    public function drainPackets(): array
    {
        $out = [];
        foreach ($this->codec->drain() as $xml) {
            $kind = $this->mapper->classify($xml);
            $data = match ($kind) {
                ResponseMapper::PACKET_INIT => $this->mapper->parseInit($xml),
                ResponseMapper::PACKET_RESPONSE => $this->mapper->parseResponse($xml),
                ResponseMapper::PACKET_NOTIFY => $this->mapper->parseNotify($xml),
                ResponseMapper::PACKET_STREAM => $this->mapper->parseStream($xml),
                default => $xml,
            };
            $out[] = ['kind' => $kind, 'data' => $data, 'xml' => $xml];
        }

        return $out;
    }

    public function applyResponseStateTransition(array $response): void
    {
        $newState = ResponseMapper::statusFromString($response['status'] ?? null);
        if ($newState === null) {
            return;
        }
        $this->state = $newState;
        $this->lastReason = $response['reason'] ?? $this->lastReason;
    }

    public function markDisconnected(): void
    {
        $this->state = SessionState::Disconnected;
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        /*
         * Pending callers are blocked waiting for replies that will never
         * arrive; deliver a synthetic disconnect response so they unblock
         * with a deterministic shape instead of a timeout.
         */
        foreach ($this->pendingResolvers as $txid => $resolver) {
            ($resolver)([
                'transaction_id' => $txid,
                'command' => '',
                'status' => SessionState::Disconnected->value,
                'reason' => 'eof',
                'attrs' => [],
                'children' => [],
                'errors' => [['code' => 0, 'message' => 'engine disconnected']],
            ]);
        }
        $this->pendingResolvers = [];
        $this->inFlight = null;
        $this->breakInFlight = null;
    }

    /**
     * Try to send a command. Returns the transaction id assigned. Throws
     * COMMAND_IN_FLIGHT if the normal slot is busy with a non-continuation
     * command. Continuation commands and async-break interrupts have their
     * own slots.
     *
     * @param array<string, scalar|null> $args
     */
    public function sendCommand(
        string $command,
        array $args,
        ?string $rawPayload,
        bool $isContinuation,
        bool $isBreak,
        callable $resolver,
    ): int {
        if (!is_resource($this->socket) || !$this->isOpen()) {
            throw AdapterException::from(
                AdapterErrorCode::EngineDisconnected,
                'Cannot send command; engine is disconnected.',
                ['session_id' => $this->adapterId, 'state' => $this->state->value],
            );
        }

        if ($isBreak) {
            if (!$this->supportsAsync) {
                throw AdapterException::from(
                    AdapterErrorCode::AsyncNotSupported,
                    'break command requires supports_async=1 from the engine.',
                    ['session_id' => $this->adapterId],
                );
            }
            if ($this->breakInFlight !== null) {
                throw AdapterException::from(
                    AdapterErrorCode::CommandInFlight,
                    'A break command is already in flight.',
                );
            }
        } elseif ($this->inFlight !== null) {
            throw AdapterException::from(
                AdapterErrorCode::CommandInFlight,
                'Another command is already in flight on this session.',
                [
                    'session_id' => $this->adapterId,
                    'state' => $this->state->value,
                    'hint' => 'Wait for the in-flight command to complete or use xdebug_break_execution if supported.',
                ],
            );
        }

        $txid = $this->nextTransactionId();
        $line = $this->encoder->encode($command, $txid, $args, $rawPayload);
        $bytes = DbgpPacketCodec::encodeCommand($line);

        $written = @fwrite($this->socket, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            $this->markDisconnected();
            throw AdapterException::from(
                AdapterErrorCode::EngineDisconnected,
                'Failed to write command to engine socket.',
                ['session_id' => $this->adapterId],
            );
        }

        if ($isBreak) {
            $this->breakInFlight = ['txid' => $txid, 'command' => $command, 'started_at' => microtime(true)];
        } else {
            $this->inFlight = [
                'txid' => $txid,
                'command' => $command,
                'started_at' => microtime(true),
                'is_continuation' => $isContinuation,
            ];
        }
        $this->pendingResolvers[$txid] = $resolver;
        $this->logger->debug('dbgp.command.sent', [
            'session' => $this->adapterId,
            'txid' => $txid,
            'command' => $command,
            'continuation' => $isContinuation,
            'break' => $isBreak,
        ]);

        return $txid;
    }

    public function deliverResponse(array $response): void
    {
        $txid = (int) ($response['transaction_id'] ?? 0);
        $resolver = $this->pendingResolvers[$txid] ?? null;
        if ($resolver !== null) {
            unset($this->pendingResolvers[$txid]);
            ($resolver)($response);
        }
        if ($this->breakInFlight !== null && $this->breakInFlight['txid'] === $txid) {
            $this->breakInFlight = null;
        } elseif ($this->inFlight !== null && $this->inFlight['txid'] === $txid) {
            $this->inFlight = null;
        }
    }

    public function inFlight(): ?array
    {
        return $this->inFlight;
    }

    public function isBreakInFlight(): bool
    {
        return $this->breakInFlight !== null;
    }
}
