<?php

declare(strict_types=1);

// Minimal subject script for the Docker example. Set a breakpoint on the
// echo line via the MCP server, then hit http://localhost:8080/.

$visit = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
echo 'hello from container, agent=' . $visit;
