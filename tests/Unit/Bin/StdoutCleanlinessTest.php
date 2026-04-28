<?php

declare(strict_types=1);

namespace Tests\Unit\Bin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression test: stdio mode must never write to stdout outside the MCP
 * JSON-RPC channel, even when bootstrap fails. We exercise the bin entry
 * with a deliberately broken config path and assert that nothing reaches
 * stdout.
 */
final class StdoutCleanlinessTest extends TestCase
{
    #[Test]
    public function it_keeps_stdout_clean_when_bootstrap_fails(): void
    {
        $bin = dirname(__DIR__, 3) . '/bin/php-xdebug-mcp';
        $brokenCfg = sys_get_temp_dir() . '/php-xdebug-mcp-broken-' . uniqid() . '.php';
        file_put_contents($brokenCfg, "<?php throw new \\RuntimeException('forced bootstrap failure'); ?>");
        $cmd = sprintf('%s %s --config=%s 2>/tmp/php-xdebug-mcp-stderr-test.log', escapeshellarg(PHP_BINARY), escapeshellarg($bin), escapeshellarg($brokenCfg));
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($proc);
        @fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        @fclose($pipes[1]);
        proc_close($proc);
        @unlink($brokenCfg);

        self::assertSame('', trim($stdout), 'stdout must be empty when bootstrap fails (logs go to stderr).');
    }
}
