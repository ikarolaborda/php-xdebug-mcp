# FPM / web request example

This recipe debugs a request handled by PHP-FPM behind nginx.

## /etc/php/8.3/fpm/conf.d/99-xdebug.ini

```ini
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=trigger
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.idekey=mcp
```

## Trigger

Add `?XDEBUG_TRIGGER=1` to the URL, set the `XDEBUG_SESSION=mcp` cookie,
or send `X-Xdebug-Trigger: mcp` header. The helper tool
`php_debug_http_request` will set the cookie for you:

```jsonc
// MCP tool call
{
  "name": "php_debug_http_request",
  "arguments": {
    "url": "http://localhost/index.php",
    "method": "GET",
    "cookie_name": "XDEBUG_SESSION",
    "cookie_value": "mcp"
  }
}
```

## Path mapping

If FPM runs in a chroot or separate filesystem from the agent, configure
`path_rules` accordingly. A typical case where `/var/www/html` is the
runtime root and your workspace lives under `/home/me/work/app`:

```php
'path_rules' => [
    [ 'label' => 'fpm', 'local' => '/home/me/work/app', 'remote' => '/var/www/html' ],
],
```
