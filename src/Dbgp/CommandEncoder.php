<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;

/**
 * Builds DBGp command lines.
 *
 * Spec rules (https://xdebug.org/docs/dbgp 6.3.1):
 *   - Args are space-separated key/value pairs prefixed with single dashes.
 *   - Values containing space, NUL, or quote must be wrapped in double quotes.
 *   - Inside double quotes, ", \, NUL must be backslash-escaped.
 *   - Optional trailing payload is base64-encoded after a literal " -- ".
 */
final class CommandEncoder
{
    /**
     * @param array<string, scalar|null> $args  short-name keyed flag map (e.g. ['t' => 'line', 'n' => 12])
     * @param string|null               $base64Payload optional payload to attach after " -- " (already base64 if not null/empty raw)
     */
    public function encode(string $command, int $transactionId, array $args = [], ?string $rawPayload = null): string
    {
        if ($command === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $command) !== 1) {
            throw AdapterException::from(
                AdapterErrorCode::InvalidArgument,
                'Invalid DBGp command name: ' . $command,
            );
        }

        $parts = [$command];
        $parts[] = '-i';
        $parts[] = (string) $transactionId;

        foreach ($args as $flag => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if (preg_match('/^[a-zA-Z]$/', (string) $flag) !== 1) {
                throw AdapterException::from(
                    AdapterErrorCode::InvalidArgument,
                    'DBGp argument flag must be a single ASCII letter; got: ' . $flag,
                );
            }
            $parts[] = '-' . $flag;
            $parts[] = self::quoteValue((string) $value);
        }

        $line = implode(' ', $parts);

        if ($rawPayload !== null && $rawPayload !== '') {
            $line .= ' -- ' . base64_encode($rawPayload);
        }

        return $line;
    }

    public static function quoteValue(string $v): string
    {
        $needsQuoting = $v === '' || strpbrk($v, " \"\\\x00") !== false;
        if (!$needsQuoting) {
            return $v;
        }
        $escaped = strtr($v, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\x00" => '\\0',
        ]);

        return '"' . $escaped . '"';
    }
}
