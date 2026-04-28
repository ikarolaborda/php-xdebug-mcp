<?php

declare(strict_types=1);

namespace PhpXdebugMcp\Domain\Events;

enum EventKind: string
{
    case SessionCreated = 'session.created';
    case SessionStateChanged = 'session.state_changed';
    case SessionDisconnected = 'session.disconnected';
    case SessionTerminated = 'session.terminated';
    case Claimed = 'session.claimed';
    case Released = 'session.released';
    case BreakpointInstalled = 'breakpoint.installed';
    case BreakpointRemoved = 'breakpoint.removed';
    case BreakpointResolved = 'breakpoint.resolved';
    case BreakpointFailed = 'breakpoint.failed';
    case PathRuleMissing = 'diagnostics.path_rule_missing';
    case CommandSent = 'command.sent';
    case CommandReplied = 'command.replied';
    case Notification = 'engine.notification';
    case StreamData = 'engine.stream_data';
    case Error = 'engine.error';
}
