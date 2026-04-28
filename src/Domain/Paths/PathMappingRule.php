<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

/**
 * One configured local <-> remote mapping rule. Higher precedence wins ties.
 */
final readonly class PathMappingRule
{
    /**
     * @param array<string, string> $exactFiles map of local-absolute -> remote-absolute (no URI scheme)
     */
    public function __construct(
        public string $localRoot,
        public string $remoteRoot,
        public array $exactFiles = [],
        public int $precedence = 100,
        public string $label = '',
    ) {
    }
}
