<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

/**
 * One configured local <-> remote mapping rule. Higher precedence wins ties.
 */
final class PathMappingRule
{
    /**
     * @param array<string, string> $exactFiles map of local-absolute -> remote-absolute (no URI scheme)
     */
    public function __construct(
        public readonly string $localRoot,
        public readonly string $remoteRoot,
        public readonly array $exactFiles = [],
        public readonly int $precedence = 100,
        public readonly string $label = '',
    ) {
    }
}
