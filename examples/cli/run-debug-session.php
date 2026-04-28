<?php

declare(strict_types=1);

/**
 * Manual reproduction recipe for a CLI debug session.
 *
 * Run two terminals.
 *
 *  Terminal 1 (the MCP server):
 *      php bin/php-xdebug-mcp
 *
 *  Terminal 2 (the subject under debug — use this script):
 *      XDEBUG_TRIGGER=1 \
 *      php -dzend_extension=xdebug \
 *          -dxdebug.mode=debug \
 *          -dxdebug.start_with_request=trigger \
 *          -dxdebug.client_host=127.0.0.1 \
 *          -dxdebug.client_port=9003 \
 *          examples/cli/run-debug-session.php
 *
 *  In the agent: xdebug_wait_for_session, xdebug_set_breakpoint with
 *  file_path = realpath of this script and lineno = 28, xdebug_continue.
 */

function add(int $a, int $b): int
{
    $sum = $a + $b;

    return $sum;
}

$result = 0;
foreach (range(1, 5) as $i) {
    $result = add($result, $i);
}

echo 'sum=' . $result . PHP_EOL;
