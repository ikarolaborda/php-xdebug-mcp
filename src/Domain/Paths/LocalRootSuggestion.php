<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

/**
 * A proposed mapping rule the user could add. Returned by
 * PathMapper::suggestLikelyLocalRoot when the inverse-mapping diagnostic
 * fires and we can hint at a probable local workspace root.
 */
final class LocalRootSuggestion
{
    public function __construct(
        public readonly string $localRoot,
        public readonly string $remoteRoot,
        public readonly int $overlapSegments,
    ) {
    }

    public function toArray(): array
    {
        return [
            'local_root' => $this->localRoot,
            'remote_root' => $this->remoteRoot,
            'overlap_segments' => $this->overlapSegments,
        ];
    }
}
