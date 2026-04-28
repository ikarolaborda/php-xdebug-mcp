<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Services;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Domain\Paths\FrameKind;
use PhpXdebugMcp\Domain\Paths\MappingStatus;
use PhpXdebugMcp\Domain\Paths\PathMappingResult;
use PhpXdebugMcp\Domain\Paths\PathMappingRule;
use PhpXdebugMcp\Domain\Paths\LocalRootSuggestion;

/**
 * Adapter-level path mapping.
 *
 * - Local <-> remote conversion via configured rules (longest matching
 *   localRoot or remoteRoot wins; ties broken by precedence).
 * - URL encoding for `file://` URIs.
 * - Windows drive letters preserved.
 * - Synthetic locations (eval'd code, internal frames) are recognised and
 *   returned with FrameKind != File and status NotApplicable.
 */
final readonly class PathMapper
{
    /** @var list<PathMappingRule> */
    private array $rules;

    /** @param list<PathMappingRule> $rules */
    public function __construct(array $rules)
    {
        usort($rules, static fn (PathMappingRule $a, PathMappingRule $b): int => $b->precedence <=> $a->precedence);
        $this->rules = $rules;
    }

    /**
     * Convert a local workspace path to a remote `file://` URI usable by
     * Xdebug. Throws PATH_MAPPING_FAILED only if the path is non-empty but
     * absolute and no matching rule exists; relative paths are rejected.
     */
    public function toRemoteUri(string $localPath): PathMappingResult
    {
        if ($localPath === '') {
            throw AdapterException::from(AdapterErrorCode::PathMappingFailed, 'Empty local path.');
        }

        $localAbs = self::canonicalLocal($localPath);

        foreach ($this->rules as $rule) {
            if (isset($rule->exactFiles[$localAbs])) {
                $remote = $rule->exactFiles[$localAbs];

                return new PathMappingResult(
                    kind: FrameKind::File,
                    localPath: $localAbs,
                    remoteUri: self::pathToFileUri($remote),
                    status: MappingStatus::Mapped,
                    ruleLabel: $rule->label,
                );
            }
            $localRoot = self::stripTrailingSlash($rule->localRoot);
            if ($localRoot === '' || !self::isUnder($localAbs, $localRoot)) {
                continue;
            }
            $tail = ltrim(substr($localAbs, strlen($localRoot)), '/\\');
            $remoteRoot = self::stripTrailingSlash($rule->remoteRoot);
            $remote = $remoteRoot . '/' . str_replace('\\', '/', $tail);

            return new PathMappingResult(
                kind: FrameKind::File,
                localPath: $localAbs,
                remoteUri: self::pathToFileUri($remote),
                status: MappingStatus::Mapped,
                ruleLabel: $rule->label,
            );
        }

        if (self::isLocalAbsolute($localAbs)) {
            /*
             * No explicit rule, but the path looks absolute on this OS,
             * so assume the runtime sees the same path (typical when
             * the MCP server and the PHP runtime share a filesystem).
             */
            return new PathMappingResult(
                kind: FrameKind::File,
                localPath: $localAbs,
                remoteUri: self::pathToFileUri($localAbs),
                status: MappingStatus::Mapped,
                ruleLabel: 'identity',
                warnings: ['No mapping rule matched; assumed identity (local path == remote path).'],
            );
        }

        throw AdapterException::from(
            AdapterErrorCode::PathMappingFailed,
            'Cannot map relative or invalid path to a remote URI: ' . $localPath,
            ['hint' => 'Pass an absolute local path or configure a path_rules entry.'],
        );
    }

    /**
     * Convert a remote URI from a stack frame or notification into a local
     * path for display. Synthetic URIs (dbgp://, eval://, xdebug://, no scheme)
     * are returned with the appropriate FrameKind.
     */
    public function fromRemoteUri(string $remoteUri): PathMappingResult
    {
        if ($remoteUri === '') {
            return new PathMappingResult(FrameKind::Unknown, null, '', MappingStatus::NotApplicable);
        }

        $synthetic = self::syntheticKind($remoteUri);
        if ($synthetic !== null) {
            return new PathMappingResult(
                kind: $synthetic,
                localPath: null,
                remoteUri: $remoteUri,
                status: MappingStatus::NotApplicable,
                warnings: ['Frame is synthetic (' . $synthetic->value . ') and has no on-disk file.'],
            );
        }

        $remotePath = self::fileUriToPath($remoteUri);
        if ($remotePath === null) {
            return new PathMappingResult(
                kind: FrameKind::Unknown,
                localPath: null,
                remoteUri: $remoteUri,
                status: MappingStatus::Unmapped,
                warnings: ['Unrecognised URI scheme; left unmapped.'],
            );
        }

        foreach ($this->rules as $rule) {
            $remoteRoot = self::stripTrailingSlash($rule->remoteRoot);
            foreach ($rule->exactFiles as $localAbs => $r) {
                if (self::pathsEqual($remotePath, $r)) {
                    return new PathMappingResult(
                        kind: FrameKind::File,
                        localPath: $localAbs,
                        remoteUri: $remoteUri,
                        status: MappingStatus::Mapped,
                        ruleLabel: $rule->label,
                    );
                }
            }
            if ($remoteRoot !== '' && self::isUnder($remotePath, $remoteRoot)) {
                $tail = ltrim(substr($remotePath, strlen($remoteRoot)), '/\\');
                $local = self::stripTrailingSlash($rule->localRoot) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $tail);

                return new PathMappingResult(
                    kind: FrameKind::File,
                    localPath: $local,
                    remoteUri: $remoteUri,
                    status: MappingStatus::Mapped,
                    ruleLabel: $rule->label,
                );
            }
        }

        /*
         * No rule matched. If the remote path is locally absolute,
         * fall back to identity mapping — common when a remote shell
         * shares the host filesystem (eg ssh to the same machine).
         */
        if (self::isLocalAbsolute($remotePath)) {
            return new PathMappingResult(
                kind: FrameKind::File,
                localPath: $remotePath,
                remoteUri: $remoteUri,
                status: MappingStatus::Mapped,
                ruleLabel: 'identity',
                warnings: ['No rule matched; assumed identity mapping.'],
            );
        }

        return new PathMappingResult(
            kind: FrameKind::File,
            localPath: null,
            remoteUri: $remoteUri,
            status: MappingStatus::Unmapped,
            warnings: ['No mapping rule matched and remote path is not locally absolute.'],
        );
    }

    /**
     * Returns the first configured rule whose remoteRoot covers the given
     * remote URI (or null). Synthetic URIs return null.
     */
    public function findRuleForRemote(string $remoteUri): ?PathMappingRule
    {
        if (self::syntheticKind($remoteUri) !== null) {
            return null;
        }
        $remotePath = self::fileUriToPath($remoteUri) ?? $remoteUri;

        return array_find($this->rules, static function (PathMappingRule $rule) use ($remotePath): bool {
            $matchesExact = array_any(
                $rule->exactFiles,
                static fn (string $remote): bool => self::pathsEqual($remotePath, $remote),
            );
            if ($matchesExact) {
                return true;
            }
            $remoteRoot = self::stripTrailingSlash($rule->remoteRoot);

            return $remoteRoot !== '' && self::isUnder($remotePath, $remoteRoot);
        });
    }

    public function rulesConfigured(): bool
    {
        return $this->rules !== [];
    }

    /**
     * Walk the configured workspace roots and return the suggestion whose
     * trailing path-segment overlap with the remote path is the longest.
     * Requires at least minOverlap matching segments to avoid false positives
     * in monorepos where every project has an `app` or `src` directory.
     *
     * @param list<string> $workspaceRoots
     */
    public function suggestLikelyLocalRoot(array $workspaceRoots, string $remoteUri, int $minOverlap = 1): ?LocalRootSuggestion
    {
        if (self::syntheticKind($remoteUri) !== null) {
            return null;
        }
        $remotePath = self::fileUriToPath($remoteUri) ?? $remoteUri;
        if (!self::isLocalAbsolute($remotePath)) {
            return null;
        }
        $remoteParts = array_values(array_filter(explode('/', str_replace('\\', '/', $remotePath)), static fn (string $p): bool => $p !== ''));
        /*
         * Drop the trailing filename so the suffix overlap compares
         * directory tails (`/var/www/html/app`) rather than mixing in
         * the leaf (`Index.php`).
         */
        if ($remoteParts !== [] && str_contains(end($remoteParts), '.')) {
            array_pop($remoteParts);
        }
        if ($remoteParts === []) {
            return null;
        }

        /*
         * Scoring: longest trailing-segment overlap wins. Ties are
         * broken by depth (shallower candidates beat deeply-nested
         * ones — in monorepos with duplicated `app` / `src` tails the
         * user typically wants the directory closer to the workspace
         * root). The final tie-break is lexical so the suggestion is
         * deterministic across runs.
         */
        $best = null;
        $bestDepth = PHP_INT_MAX;
        foreach ($workspaceRoots as $root) {
            $rootAbs = self::canonicalLocal($root);
            if (!self::isLocalAbsolute($rootAbs)) {
                continue;
            }
            $rootDepth = count(array_filter(explode('/', str_replace('\\', '/', $rootAbs)), static fn (string $p): bool => $p !== ''));
            $rootDirs = self::collectImmediateDirs($rootAbs);
            $candidates = array_merge([$rootAbs], $rootDirs);
            foreach ($candidates as $candidate) {
                $localParts = array_values(array_filter(explode('/', str_replace('\\', '/', $candidate)), static fn (string $p): bool => $p !== ''));
                $overlap = self::trailingOverlap($localParts, $remoteParts);
                if ($overlap < $minOverlap) {
                    continue;
                }
                $remoteRoot = '/' . implode('/', array_slice($remoteParts, 0, count($remoteParts) - $overlap));
                if ($remoteRoot === '/') {
                    continue;
                }
                $depthBelowRoot = max(0, count($localParts) - $rootDepth);
                $isBetter = $best === null
                    || $overlap > $best->overlapSegments
                    || ($overlap === $best->overlapSegments && $depthBelowRoot < $bestDepth)
                    || ($overlap === $best->overlapSegments && $depthBelowRoot === $bestDepth && strcmp($candidate, $best->localRoot) < 0);
                if (!$isBetter) {
                    continue;
                }
                $best = new LocalRootSuggestion(
                    localRoot: $candidate,
                    remoteRoot: $remoteRoot,
                    overlapSegments: $overlap,
                );
                $bestDepth = $depthBelowRoot;
            }
        }

        return $best;
    }

    /**
     * Collect candidate directories under $root up to a small depth (default 2)
     * so typical workspace layouts like `workspace/project/app` and
     * `workspace/app` are both reachable. Skips hidden directories,
     * `vendor`, and `node_modules` to avoid expensive walks.
     *
     * @return list<string>
     */
    private static function collectImmediateDirs(string $root, int $depth = 2): array
    {
        if (!is_dir($root) || $depth <= 0) {
            return [];
        }
        $out = [];
        $skip = ['.', '..', '.git', 'vendor', 'node_modules', '.idea', '.vscode'];
        foreach (@scandir($root) ?: [] as $entry) {
            if (in_array($entry, $skip, true) || ($entry !== '' && $entry[0] === '.')) {
                continue;
            }
            $full = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($full)) {
                continue;
            }
            $out[] = $full;
            if ($depth > 1) {
                foreach (self::collectImmediateDirs($full, $depth - 1) as $deeper) {
                    $out[] = $deeper;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function trailingOverlap(array $a, array $b): int
    {
        $i = count($a) - 1;
        $j = count($b) - 1;
        $count = 0;
        while ($i >= 0 && $j >= 0) {
            if (self::segEq($a[$i], $b[$j])) {
                $count++;
                $i--;
                $j--;
                continue;
            }
            break;
        }

        return $count;
    }

    private static function segEq(string $a, string $b): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($a, $b) === 0;
        }

        return $a === $b;
    }

    public static function pathToFileUri(string $absPath): string
    {
        $normalised = str_replace('\\', '/', $absPath);
        if (preg_match('/^[A-Za-z]:\//', $normalised) === 1) {
            return 'file:///' . self::encodeSegments($normalised);
        }
        if (str_starts_with($normalised, '/')) {
            return 'file://' . self::encodeSegments(substr($normalised, 1), prefix: '/');
        }

        return 'file://' . self::encodeSegments($normalised);
    }

    private static function encodeSegments(string $path, string $prefix = ''): string
    {
        $parts = explode('/', $path);
        foreach ($parts as $i => $p) {
            $parts[$i] = rawurlencode($p);
        }

        return $prefix . implode('/', $parts);
    }

    public static function fileUriToPath(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }
        $path = rawurldecode(substr($uri, 7));
        if ($path === '') {
            return null;
        }
        /*
         * Windows file URIs follow the `file:///C:/...` form; strip the
         * leading `/` so the result is a usable Windows-style path.
         */
        if (preg_match('#^/([A-Za-z]):/#', $path) === 1) {
            $path = substr($path, 1);
        }

        return $path;
    }

    private static function syntheticKind(string $uri): ?FrameKind
    {
        if (str_starts_with($uri, 'dbgp://')) {
            return FrameKind::Eval;
        }
        if (str_starts_with($uri, 'xdebug://')) {
            return FrameKind::Internal;
        }
        if (str_starts_with($uri, 'eval://')) {
            return FrameKind::Eval;
        }
        if ($uri === 'internal' || str_starts_with($uri, 'internal:')) {
            return FrameKind::Internal;
        }

        return null;
    }

    private static function canonicalLocal(string $path): string
    {
        $p = rtrim($path);
        if (DIRECTORY_SEPARATOR === '/') {
            return $p;
        }

        return str_replace('/', '\\', $p);
    }

    private static function stripTrailingSlash(string $p): string
    {
        if ($p === '') {
            return '';
        }
        $clean = rtrim($p, "/\\");

        return $clean === '' ? $p[0] : $clean;
    }

    private static function isUnder(string $path, string $root): bool
    {
        $p = self::normalizeForCompare($path);
        $r = self::normalizeForCompare($root);
        if ($r === '' || $p === '') {
            return false;
        }
        if ($p === $r) {
            return true;
        }

        return str_starts_with($p, $r . '/');
    }

    private static function normalizeForCompare(string $p): string
    {
        $n = str_replace('\\', '/', $p);
        if (preg_match('/^[A-Za-z]:\//', $n) === 1) {
            $n = strtoupper($n[0]) . substr($n, 1);
        }

        return rtrim($n, '/');
    }

    private static function isLocalAbsolute(string $p): bool
    {
        return str_starts_with($p, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $p) === 1;
    }

    private static function pathsEqual(string $a, string $b): bool
    {
        return self::normalizeForCompare($a) === self::normalizeForCompare($b);
    }
}
