# Path mapping cookbook

The adapter never trusts native Xdebug path mapping. It always normalises
both directions itself, so you can drive it identically from a CLI host,
a Docker container, or an SSH-mounted remote.

## Concepts

- **local path** — an absolute path on the machine running the agent.
- **remote URI** — a `file://` URI as understood by the runtime.
- **rule** — `{ local, remote, exact, precedence, label }`. Higher
  precedence wins ties.
- **synthetic frame** — `dbgp://`, `xdebug://`, `eval://`, `internal:`.
  Returned with `mapping_status=not_applicable` and `local_path=null`.

## Rule shape

```php
return [
    'path_rules' => [
        [
            'label' => 'docker app',
            'local' => '/Users/me/work/app',
            'remote' => '/var/www/html',
            'exact' => [
                '/Users/me/work/app/special/x.php' => '/srv/legacy/x.php',
            ],
            'precedence' => 100,
        ],
    ],
];
```

## Recipes

**Identical paths on host and runtime.** No rule needed. The adapter
falls back to identity mapping and adds a warning to the result so the
agent knows there was no explicit configuration.

**Docker compose with a mounted source tree.**

```php
[
    'label' => 'docker',
    'local' => '/Users/me/work/app',
    'remote' => '/var/www/html',
],
```

**SSH mount where the runtime has a different home.**

```php
[
    'label' => 'remote',
    'local' => '/home/me/projects/app',
    'remote' => '/data/projects/app',
],
```

**One file is symlinked into a different name.**

```php
[
    'label' => 'overrides',
    'local' => '/home/me/work/app',
    'remote' => '/var/www',
    'exact' => [
        '/home/me/work/app/composer.json' => '/var/www/composer.json',
        '/home/me/work/app/.env.production' => '/etc/app/.env',
    ],
],
```

**Windows host running a Linux container.** Forward slashes are accepted
on input; Windows drive letters are preserved.

```php
[
    'label' => 'windows host',
    'local' => 'C:/Users/me/proj',
    'remote' => '/var/www/html',
],
```

## How responses look

Every tool that returns a frame includes a normalised mapping record:

```json
{
  "filename": "file:///var/www/html/app/Index.php",
  "mapped": {
    "kind": "file",
    "local_path": "/Users/me/work/app/app/Index.php",
    "remote_uri": "file:///var/www/html/app/Index.php",
    "mapping_status": "mapped",
    "rule": "docker app",
    "warnings": []
  }
}
```

Synthetic frames look like:

```json
{
  "kind": "eval",
  "local_path": null,
  "remote_uri": "dbgp://eval/4",
  "mapping_status": "not_applicable",
  "warnings": ["Frame is synthetic (eval) and has no on-disk file."]
}
```

## When mapping fails

`PATH_MAPPING_FAILED` is reserved for operations that *require* a
mappable file path: setting a line breakpoint, asking for source. For
read-only stack frames, the adapter prefers to return an `Unmapped`
status with a warning rather than fail the whole tool.

## Inverse-mapping diagnostics

When at least one rule is configured **and** an incoming session reports
a `fileuri` that no rule covers, the adapter attaches a structured
warning to the session:

```jsonc
"warnings": [
  {
    "code": "PATH_RULE_MISSING",
    "message": "Engine session reports a fileuri (...) that no configured path rule covers.",
    "context": {
      "remote_fileuri": "file:///var/www/html/app/Index.php",
      "suggested_rule": {
        "local_root": "/Users/me/projects/myapp",
        "remote_root": "/var/www/html",
        "overlap_segments": 2
      }
    },
    "hint": "Try adding a path_rules entry: local=/Users/me/projects/myapp, remote=/var/www/html"
  }
]
```

The suggestion is computed by walking each `workspace_roots` entry up
to depth 2 and picking the candidate whose trailing path segments
overlap most with the remote path's tail (minimum 1 segment). It is
deliberately conservative: false positives in monorepos with
identically-named subdirectories are noisier than no suggestion at all.

The diagnostic is **advisory** — it never blocks the session or its
tools. It also never fires when there are zero rules configured, since
identity mapping is the intentional behaviour in that case.
