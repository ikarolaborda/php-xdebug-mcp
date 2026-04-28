<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Dbgp;

/**
 * Pure helpers for feature negotiation. The actual round-trips are done by
 * DbgpSession; this class just declares the canonical lists and the
 * preferred feature_set values.
 */
final class FeatureNegotiator
{
    /**
     * Features we always probe via feature_get on session init.
     *
     * @return list<string>
     */
    public static function probeList(): array
    {
        return [
            'language_name',
            'language_version',
            'protocol_version',
            'supports_async',
            'breakpoint_types',
            'multiple_sessions',
            'max_children',
            'max_data',
            'max_depth',
            'breakpoint_details',
            'extended_properties',
            'notify_ok',
            'resolved_breakpoints',
            'supported_encodings',
            'supports_postmortem',
            'show_hidden',
            'data_encoding',
            'language_supports_threads',
        ];
    }

    /**
     * Preferred feature_set values to apply after probing. Keys are feature
     * names, values are their preferred string representation. Apply only if
     * the feature is reported as supported by the engine.
     *
     * @param array{max_children?:int, max_data?:int, max_depth?:int} $overrides
     * @return array<string, string>
     */
    public static function preferredSettings(array $overrides = []): array
    {
        $maxChildren = (string) ($overrides['max_children'] ?? 100);
        $maxData = (string) ($overrides['max_data'] ?? 4096);
        $maxDepth = (string) ($overrides['max_depth'] ?? 3);

        return [
            'multiple_sessions' => '1',
            'extended_properties' => '1',
            'notify_ok' => '1',
            'breakpoint_details' => '1',
            'resolved_breakpoints' => '1',
            'show_hidden' => '1',
            'max_children' => $maxChildren,
            'max_data' => $maxData,
            'max_depth' => $maxDepth,
        ];
    }
}
