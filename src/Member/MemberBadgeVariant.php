<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Member;

/**
 * Since 2.18.0 (Beacon spec 234, member badges): how a {@see MemberBadge} looks. An allow-list, never a CSS class from the
 * host: the bundle maps each variant to its own classes (`nowo-task-member-badge--<variant>` plus a UiKit badge class).
 */
enum MemberBadgeVariant: string
{
    case Neutral = 'neutral';
    case Info    = 'info';
    case Success = 'success';
    case Warning = 'warning';
    /** A service account (an API token, an MCP agent). */
    case Agent = 'agent';
}
