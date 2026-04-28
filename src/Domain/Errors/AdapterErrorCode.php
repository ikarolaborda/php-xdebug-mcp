<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Errors;

enum AdapterErrorCode: string
{
    case SessionNotFound = 'SESSION_NOT_FOUND';
    case SessionAmbiguous = 'SESSION_AMBIGUOUS';
    case SessionNotClaimed = 'SESSION_NOT_CLAIMED';
    case SessionAlreadyClaimed = 'SESSION_ALREADY_CLAIMED';
    case InvalidSessionState = 'INVALID_SESSION_STATE';
    case AsyncNotSupported = 'ASYNC_NOT_SUPPORTED';
    case FeatureUnsupported = 'FEATURE_UNSUPPORTED';
    case PathMappingFailed = 'PATH_MAPPING_FAILED';
    case BreakpointValidationFailed = 'BREAKPOINT_VALIDATION_FAILED';
    case CommandInFlight = 'COMMAND_IN_FLIGHT';
    case EngineDisconnected = 'ENGINE_DISCONNECTED';
    case EngineProtocolError = 'ENGINE_PROTOCOL_ERROR';
    case Timeout = 'TIMEOUT';
    case AccessDenied = 'ACCESS_DENIED';
    case PayloadTruncated = 'PAYLOAD_TRUNCATED';
    case InvalidArgument = 'INVALID_ARGUMENT';
}
