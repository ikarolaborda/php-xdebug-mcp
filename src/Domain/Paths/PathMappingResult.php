<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

/**
 * The shape returned by PathMapper for both directions. Synthetic frames
 * carry kind != File and mapped_path may be null. Callers must inspect
 * status before treating mapped_path as a real on-disk file.
 */
final readonly class PathMappingResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public FrameKind $kind,
        public ?string $localPath,
        public ?string $remoteUri,
        public MappingStatus $status,
        public ?string $ruleLabel = null,
        public array $warnings = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'local_path' => $this->localPath,
            'remote_uri' => $this->remoteUri,
            'mapping_status' => $this->status->value,
            'rule' => $this->ruleLabel,
            'warnings' => $this->warnings,
        ];
    }
}
