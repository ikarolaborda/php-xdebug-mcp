<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;

/**
 * DBGp wire framing.
 *
 * Engine -> IDE: <ascii_int_length> NUL <XML payload> NUL
 * IDE -> engine: <command line> NUL  (no length prefix)
 *
 * The decoder is incremental. Feed it bytes via append(); it returns zero or
 * more raw XML payloads as bytes are consumed. Any unparsed remainder stays
 * in the internal buffer until more bytes arrive.
 *
 * Reference: https://xdebug.org/docs/dbgp section 4.
 */
final class DbgpPacketCodec
{
    private string $buffer = '';

    public const MAX_PACKET_BYTES = 64 * 1024 * 1024;

    public function append(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->buffer .= $bytes;
    }

    /**
     * Drain as many complete frames as currently buffered.
     *
     * @return list<string> raw XML payloads (no NUL terminators)
     */
    public function drain(): array
    {
        $out = [];
        while (true) {
            $frame = $this->tryReadFrame();
            if ($frame === null) {
                break;
            }
            $out[] = $frame;
        }

        return $out;
    }

    private function tryReadFrame(): ?string
    {
        $nulPos = strpos($this->buffer, "\x00");
        if ($nulPos === false) {
            return null;
        }

        $lenStr = substr($this->buffer, 0, $nulPos);

        if ($lenStr === '' || preg_match('/^\d+$/', $lenStr) !== 1) {
            throw AdapterException::from(
                AdapterErrorCode::EngineProtocolError,
                'Malformed DBGp frame: length prefix is not a non-negative ASCII integer.',
                ['hint' => 'Check Xdebug version and that we are not framing IDE->engine bytes here.'],
            );
        }

        $length = (int) $lenStr;
        if ($length < 0 || $length > self::MAX_PACKET_BYTES) {
            throw AdapterException::from(
                AdapterErrorCode::PayloadTruncated,
                'DBGp frame length out of bounds: ' . $length,
            );
        }

        $needed = $nulPos + 1 + $length + 1;
        if (strlen($this->buffer) < $needed) {
            return null;
        }

        $payload = substr($this->buffer, $nulPos + 1, $length);
        $trailingNul = $this->buffer[$nulPos + 1 + $length] ?? '';
        if ($trailingNul !== "\x00") {
            throw AdapterException::from(
                AdapterErrorCode::EngineProtocolError,
                'Malformed DBGp frame: missing trailing NUL after XML payload.',
            );
        }

        $this->buffer = substr($this->buffer, $needed);

        return $payload;
    }

    public function pendingBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Encode an IDE -> engine command line. The DBGp spec uses no length
     * prefix on the IDE side, only a NUL terminator.
     */
    public static function encodeCommand(string $commandLine): string
    {
        if (str_contains($commandLine, "\x00")) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidArgument,
                'Command line must not contain NUL bytes; use base64 -- payload for binary data.',
            );
        }

        return $commandLine . "\x00";
    }
}
