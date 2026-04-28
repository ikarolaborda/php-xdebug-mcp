<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

/**
 * A proposed mapping rule the user could add. Returned by
 * PathMapper::suggestLikelyLocalRoot when the inverse-mapping diagnostic
 * fires and we can hint at a probable local workspace root.
 */
final readonly class LocalRootSuggestion
{
    public function __construct(
        public string $localRoot,
        public string $remoteRoot,
        public int $overlapSegments,
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
