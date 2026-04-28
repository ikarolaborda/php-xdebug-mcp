<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Breakpoints;

use PhpXdebugMcp\Domain\Errors\AdapterErrorCode;
use PhpXdebugMcp\Domain\Errors\AdapterException;
use PhpXdebugMcp\Infrastructure\Ids;

/**
 * Adapter-level breakpoint record. Per-session engine ids are tracked in
 * BreakpointStore and not stored on the definition itself, so persistent
 * breakpoints can be replayed unchanged into new sessions.
 */
final readonly class BreakpointDefinition
{
    public function __construct(
        public string $adapterId,
        public BreakpointType $type,
        public BreakpointScope $scope,
        public bool $enabled,
        public bool $temporary,
        public ?string $localPath,
        public ?string $remoteUri,
        public ?int $lineno,
        public ?string $functionName,
        public ?string $exceptionName,
        public ?string $expression,
        public ?int $hitValue,
        public ?HitCondition $hitCondition,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function create(
        BreakpointType $type,
        BreakpointScope $scope,
        bool $enabled,
        bool $temporary,
        ?string $localPath,
        ?string $remoteUri,
        ?int $lineno,
        ?string $functionName,
        ?string $exceptionName,
        ?string $expression,
        ?int $hitValue,
        ?HitCondition $hitCondition,
        string $now,
    ): self {
        $definition = new self(
            adapterId: Ids::adapterBreakpointId(),
            type: $type,
            scope: $scope,
            enabled: $enabled,
            temporary: $temporary,
            localPath: $localPath,
            remoteUri: $remoteUri,
            lineno: $lineno,
            functionName: $functionName,
            exceptionName: $exceptionName,
            expression: $expression,
            hitValue: $hitValue,
            hitCondition: $hitCondition,
            createdAt: $now,
            updatedAt: $now,
        );

        $definition->validate();

        return $definition;
    }

    public function withRemoteUri(string $remoteUri, string $now): self
    {
        return clone($this, [
            'remoteUri' => $remoteUri,
            'updatedAt' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function applyPatch(array $patch, string $now): self
    {
        $next = new self(
            adapterId: $this->adapterId,
            type: $this->type,
            scope: $this->scope,
            enabled: array_key_exists('enabled', $patch) ? (bool) $patch['enabled'] : $this->enabled,
            temporary: $this->temporary,
            localPath: $this->localPath,
            remoteUri: $this->remoteUri,
            lineno: array_key_exists('lineno', $patch) ? (int) $patch['lineno'] : $this->lineno,
            functionName: $this->functionName,
            exceptionName: $this->exceptionName,
            expression: $this->expression,
            hitValue: array_key_exists('hit_value', $patch) ? (int) $patch['hit_value'] : $this->hitValue,
            hitCondition: array_key_exists('hit_condition', $patch) && $patch['hit_condition'] !== null
                ? HitCondition::from((string) $patch['hit_condition'])
                : $this->hitCondition,
            createdAt: $this->createdAt,
            updatedAt: $now,
        );

        $next->validate();

        return $next;
    }

    public function validate(): void
    {
        if ($this->type->requiresFile() && ($this->localPath === null && $this->remoteUri === null)) {
            throw AdapterException::from(
                AdapterErrorCode::BreakpointValidationFailed,
                'Breakpoint of type ' . $this->type->value . ' requires a local path or remote URI.',
                ['hint' => 'Pass file_path (preferred) or remote_uri.'],
            );
        }

        if ($this->type === BreakpointType::Line || $this->type === BreakpointType::Conditional) {
            if ($this->lineno === null || $this->lineno < 1) {
                throw AdapterException::from(
                    AdapterErrorCode::BreakpointValidationFailed,
                    'Line/conditional breakpoints require lineno >= 1.',
                );
            }
        }

        if ($this->type->requiresExpression() && ($this->expression === null || $this->expression === '')) {
            throw AdapterException::from(
                AdapterErrorCode::BreakpointValidationFailed,
                'Conditional/watch breakpoints require a non-empty expression.',
            );
        }

        if ($this->type->requiresFunction() && ($this->functionName === null || $this->functionName === '')) {
            throw AdapterException::from(
                AdapterErrorCode::BreakpointValidationFailed,
                'Call/return breakpoints require a function name.',
            );
        }

        if ($this->type->requiresException() && ($this->exceptionName === null || $this->exceptionName === '')) {
            throw AdapterException::from(
                AdapterErrorCode::BreakpointValidationFailed,
                'Exception breakpoints require an exception name.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'adapter_id' => $this->adapterId,
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'enabled' => $this->enabled,
            'temporary' => $this->temporary,
            'file_path' => $this->localPath,
            'remote_uri' => $this->remoteUri,
            'lineno' => $this->lineno,
            'function' => $this->functionName,
            'exception' => $this->exceptionName,
            'expression' => $this->expression,
            'hit_value' => $this->hitValue,
            'hit_condition' => $this->hitCondition?->value,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
