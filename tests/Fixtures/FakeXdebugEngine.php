<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Scriptable fake of the engine end of a DBGp connection. Used by contract
 * tests to drive the adapter through interesting protocol scenarios without
 * relying on a real PHP+Xdebug install.
 *
 * The fake works by reading bytes from one end of a stream pair, parsing the
 * NUL-delimited IDE->engine command lines, and producing the scripted reply
 * for the matching transaction id (or any matching command, if the script
 * uses wildcard matchers).
 */
final class FakeXdebugEngine
{
    /** @var resource */
    private $sock;

    private string $readBuffer = '';

    /** @var list<array{command:string, build:callable(int $txid, array<string,string> $args, string $payload):string}> */
    private array $script = [];

    /**
     * @param resource $socket the engine-side socket; will be configured
     *                          non-blocking
     */
    public function __construct($socket)
    {
        $this->sock = $socket;
        stream_set_blocking($socket, false);
    }

    public function sendInit(string $appId = '42', string $fileUri = 'file:///app/index.php'): void
    {
        $xml = '<?xml version="1.0" encoding="iso-8859-1"?>'
             . '<init xmlns="urn:debugger_protocol_v1" appid="' . $appId . '" idekey="MCP" '
             . 'session="" thread="0" parent="" language="PHP" protocol_version="1.0" fileuri="' . $fileUri . '"/>';
        $this->sendFrame($xml);
    }

    public function expect(string $command, callable $build): void
    {
        $this->script[] = ['command' => $command, 'build' => $build];
    }

    /**
     * Drive the fake one step: read pending command bytes, match against the
     * next script entry, and reply.
     */
    public function pump(): void
    {
        $chunk = @fread($this->sock, 65536);
        if ($chunk !== false && $chunk !== '') {
            $this->readBuffer .= $chunk;
        }
        while (true) {
            $nul = strpos($this->readBuffer, "\x00");
            if ($nul === false) {
                return;
            }
            $line = substr($this->readBuffer, 0, $nul);
            $this->readBuffer = substr($this->readBuffer, $nul + 1);
            $this->reply($line);
        }
    }

    public function sendNotify(string $name, array $attrs = [], string $body = ''): void
    {
        $attrStr = '';
        foreach ($attrs as $k => $v) {
            $attrStr .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_XML1) . '"';
        }
        $payload = $body !== '' ? base64_encode($body) : '';
        $xml = '<?xml version="1.0"?><notify xmlns="urn:debugger_protocol_v1" name="' . $name . '"' . $attrStr
             . ($payload === '' ? '/>' : ' encoding="base64">' . $payload . '</notify>');
        $this->sendFrame($xml);
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }

    private function reply(string $line): void
    {
        $parts = self::tokenise($line);
        if ($parts === []) {
            return;
        }
        $command = $parts[0];
        $args = self::parseArgs(array_slice($parts, 1));
        $txid = (int) ($args['i'] ?? '0');
        $payload = '';
        if (isset($args['__payload__'])) {
            $payload = $args['__payload__'];
            unset($args['__payload__']);
        }

        foreach ($this->script as $i => $entry) {
            if ($entry['command'] === $command || $entry['command'] === '*') {
                $xml = ($entry['build'])($txid, $args, $payload);
                unset($this->script[$i]);
                $this->script = array_values($this->script);
                $this->sendFrame($xml);

                return;
            }
        }
        // Unmatched command: send a generic ok response so the runtime
        // doesn't deadlock waiting on it.
        $xml = '<response xmlns="urn:debugger_protocol_v1" command="' . $command . '" transaction_id="' . $txid . '" status="break" reason="ok"/>';
        $this->sendFrame($xml);
    }

    private function sendFrame(string $xml): void
    {
        if (!is_resource($this->sock)) {
            return;
        }
        $bytes = strlen($xml) . "\x00" . $xml . "\x00";
        @fwrite($this->sock, $bytes);
    }

    /**
     * Tokenise a DBGp command line, respecting quoted values and the
     * trailing -- base64(payload).
     *
     * @return list<string>
     */
    private static function tokenise(string $line): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($line);
        while ($i < $len) {
            while ($i < $len && $line[$i] === ' ') {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if (substr($line, $i, 3) === '-- ') {
                $tokens[] = '--';
                $tokens[] = substr($line, $i + 3);
                break;
            }
            if ($line[$i] === '"') {
                $j = $i + 1;
                $val = '';
                while ($j < $len) {
                    $ch = $line[$j];
                    if ($ch === '\\' && $j + 1 < $len) {
                        $val .= match ($line[$j + 1]) {
                            '"' => '"',
                            '\\' => '\\',
                            '0' => "\x00",
                            default => $line[$j + 1],
                        };
                        $j += 2;
                        continue;
                    }
                    if ($ch === '"') {
                        $j++;
                        break;
                    }
                    $val .= $ch;
                    $j++;
                }
                $tokens[] = $val;
                $i = $j;
            } else {
                $j = $i;
                while ($j < $len && $line[$j] !== ' ') {
                    $j++;
                }
                $tokens[] = substr($line, $i, $j - $i);
                $i = $j;
            }
        }

        return $tokens;
    }

    /** @return array<string, string> */
    private static function parseArgs(array $tokens): array
    {
        $args = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '--' && isset($tokens[$i + 1])) {
                $decoded = base64_decode($tokens[$i + 1], true);
                $args['__payload__'] = $decoded === false ? $tokens[$i + 1] : $decoded;
                $i++;
                continue;
            }
            if (str_starts_with($t, '-') && strlen($t) === 2 && isset($tokens[$i + 1])) {
                $args[$t[1]] = $tokens[$i + 1];
                $i++;
            }
        }

        return $args;
    }
}
