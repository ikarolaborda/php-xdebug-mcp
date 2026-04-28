# GitHub Copilot agent / VS Code

In `.vscode/mcp.json` (workspace) or your user MCP config:

```jsonc
{
  "servers": {
    "php-xdebug-mcp": {
      "type": "stdio",
      "command": "php",
      "args": ["${workspaceFolder}/bin/php-xdebug-mcp"]
    }
  }
}
```

If you debug VS Code-side via the same Xdebug listener, configure the
PHP Debug extension to use `port: 9003, hostname: 127.0.0.1`. The MCP
server and the IDE debug client cannot share a single Xdebug connection,
so use separate listener ports if you want both running at once
(`XDEBUG_MCP_LISTEN_PORT=9013` for the agent, default `9003` for the IDE).
