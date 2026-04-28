<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Errors;

use RuntimeException;
use Throwable;

/**
 * Adapter-domain exception with machine-readable code, human message,
 * optional original DBGp error, session reference, and a hint for the
 * agent's next action.
 *
 * The MCP layer translates these into the structured tool error envelope.
 */
final class AdapterException extends RuntimeException
{
    public function __construct(
        public readonly AdapterErrorCode $errorCode,
        string $message,
        public readonly ?int $dbgpErrorCode = null,
        public readonly ?string $dbgpMessage = null,
        public readonly ?string $adapterSessionId = null,
        public readonly ?string $sessionState = null,
        public readonly ?string $hint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function from(AdapterErrorCode $code, string $message, array $extra = []): self
    {
        return new self(
            errorCode: $code,
            message: $message,
            dbgpErrorCode: isset($extra['dbgp_code']) ? (int) $extra['dbgp_code'] : null,
            dbgpMessage: $extra['dbgp_message'] ?? null,
            adapterSessionId: $extra['session_id'] ?? null,
            sessionState: $extra['state'] ?? null,
            hint: $extra['hint'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toEnvelope(): array
    {
        return [
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'dbgp_code' => $this->dbgpErrorCode,
            'dbgp_message' => $this->dbgpMessage,
            'session_id' => $this->adapterSessionId,
            'state' => $this->sessionState,
            'hint' => $this->hint,
        ];
    }
}
