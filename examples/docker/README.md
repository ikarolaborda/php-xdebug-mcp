# Docker example

```
docker compose -f examples/docker/docker-compose.yml up -d
```

Adapter `path_rules` for this layout:

```php
'path_rules' => [
    [
        'label' => 'docker app',
        'local' => '/abs/path/to/repo/examples/docker',
        'remote' => '/var/www/html',
    ],
],
```

In the agent:

1. `xdebug_wait_for_session` (timeout 60000)
2. trigger a request: `php_debug_http_request` with `url=http://localhost:8080/`
3. `xdebug_claim_session`
4. `xdebug_set_breakpoint` with file_path equal to the local
   `examples/docker/index.php` and `lineno=8`
5. `xdebug_continue` then `xdebug_get_stack` once it breaks
