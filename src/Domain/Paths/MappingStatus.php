<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Paths;

/**
 * Adapter-side mapping status used in stack frames and source-location
 * responses. We never throw PATH_MAPPING_FAILED for synthetic frames; we
 * return them with NotApplicable instead.
 */
enum MappingStatus: string
{
    case Mapped = 'mapped';
    case Unmapped = 'unmapped';
    case NotApplicable = 'not_applicable';
    case Failed = 'failed';
}
