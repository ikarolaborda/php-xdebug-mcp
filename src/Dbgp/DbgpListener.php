<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use Psr\Log\LoggerInterface;

/**
 * Owns the listening TCP socket. Xdebug connects back to it.
 *
 * Loopback-only by default; non-loopback bind requires explicit config.
 */
final class DbgpListener
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly LoggerInterface $logger,
        private readonly bool $allowNonLoopback,
        private readonly array $allowedClientIps,
    ) {
    }

    public function start(): void
    {
        if ($this->socket !== null) {
            return;
        }

        if (!$this->allowNonLoopback && !in_array($this->host, ['127.0.0.1', '::1'], true)) {
            throw AdapterException::from(
                AdapterErrorCode::AccessDenied,
                'Refusing to bind to non-loopback host without explicit opt-in: ' . $this->host,
                ['hint' => 'Set http_enabled / explicit binding only for trusted environments.'],
            );
        }

        $errno = 0;
        $errstr = '';
        $address = (str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host) . ':' . $this->port;
        $sock = @stream_socket_server('tcp://' . $address, $errno, $errstr);
        if ($sock === false) {
            throw AdapterException::from(
                AdapterErrorCode::EngineDisconnected,
                'Failed to bind DBGp listener on ' . $address . ': ' . $errstr,
                ['hint' => 'Is another debugger already bound to that port?'],
            );
        }
        stream_set_blocking($sock, false);
        $this->socket = $sock;
        $this->logger->info('dbgp.listener.started', ['address' => $address]);
    }

    /**
     * @return list<array{socket:resource, peer:string}>
     */
    public function acceptPending(): array
    {
        if ($this->socket === null) {
            return [];
        }
        $out = [];
        while (true) {
            $peer = '';
            $client = @stream_socket_accept($this->socket, 0, $peer);
            if ($client === false) {
                break;
            }
            if (!$this->isClientAllowed($peer)) {
                $this->logger->warning('dbgp.listener.rejected', ['peer' => $peer]);
                @fclose($client);
                continue;
            }
            $out[] = ['socket' => $client, 'peer' => $peer];
        }

        return $out;
    }

    public function stop(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function getAddress(): string
    {
        return $this->host . ':' . $this->port;
    }

    private function isClientAllowed(string $peer): bool
    {
        if ($this->allowedClientIps === []) {
            return true;
        }
        $host = preg_replace('/:[0-9]+$/', '', $peer) ?? $peer;
        $host = trim($host, '[]');

        return in_array($host, $this->allowedClientIps, true);
    }
}
