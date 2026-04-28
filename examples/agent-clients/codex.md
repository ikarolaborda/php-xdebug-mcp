# Codex CLI

Codex reads `~/.codex/mcp.toml`:

```toml
[[servers]]
name = "php-xdebug-mcp"
command = ["php", "/abs/path/to/xdebug-mcp/bin/php-xdebug-mcp"]

[[servers.env]]
XDEBUG_MCP_LOG_PATH = "/tmp/php-xdebug-mcp.log"
XDEBUG_MCP_SAFETY_MODE = "control"
```

Tools become available as `xdebug_*` once the next session starts.
