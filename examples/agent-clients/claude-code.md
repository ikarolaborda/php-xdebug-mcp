# Claude Code

Add this entry to your global `.claude.json` (or workspace
`.claude/settings.local.json`):

```json
{
  "mcpServers": {
    "php-xdebug-mcp": {
      "command": "php",
      "args": ["/abs/path/to/xdebug-mcp/bin/php-xdebug-mcp"],
      "env": {
        "XDEBUG_MCP_LOG_PATH": "/tmp/php-xdebug-mcp.log",
        "XDEBUG_MCP_SAFETY_MODE": "control"
      }
    }
  }
}
```

The server is available as `mcp__php-xdebug-mcp__*` tools.
