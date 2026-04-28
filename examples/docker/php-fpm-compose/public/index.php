<?php

declare(strict_types=1);

// Drop a breakpoint on line 11 from the agent (or use xdebug_run_to_cursor).
$user = $_GET['user'] ?? 'anonymous';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '*/*';

$summary = [
    'user' => $user,
    'accept' => $accept,
    'time' => time(),
];

echo json_encode($summary);
