<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Infrastructure;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Minimal PSR-3 logger that writes JSON lines to a stream resource (stderr by default).
 * Stdio MCP transport requires that stdout never receives log output, so we keep
 * it confined to the configured sink.
 */
final class StderrLogger extends AbstractLogger
{
    /** @var resource */
    private $stream;

    private static array $levelOrder = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * @param resource|string $sink     stream resource or fopen-able URI (eg. php://stderr or /tmp/log)
     * @param string          $minLevel one of PSR-3 log levels
     */
    public function __construct($sink = 'php://stderr', private readonly string $minLevel = LogLevel::INFO)
    {
        if (is_resource($sink)) {
            $this->stream = $sink;

            return;
        }

        $h = @fopen($sink, 'ab');
        if (!is_resource($h)) {
            $h = fopen('php://stderr', 'ab');
        }
        $this->stream = $h;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $lvl = is_string($level) ? $level : LogLevel::INFO;
        if (!$this->shouldEmit($lvl)) {
            return;
        }

        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $lvl,
            'msg' => (string) $message,
            'ctx' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line === false) {
            return;
        }

        @fwrite($this->stream, $line . "\n");
    }

    private function shouldEmit(string $level): bool
    {
        $cur = self::$levelOrder[$level] ?? 6;
        $min = self::$levelOrder[$this->minLevel] ?? 6;

        return $cur <= $min;
    }
}
