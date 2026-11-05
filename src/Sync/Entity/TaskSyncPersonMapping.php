<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

/**
 * Since 1.25: how a remote person is mapped. Only `auto` and `manual` are projected (written as creator, member or
 * comment author, and only where the user can view the board).
 */
enum TaskSyncPersonMapping: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Auto      = 'auto';
    case Manual    = 'manual';
    case External  = 'external';

    public function isMapped(): bool
    {
        return $this === self::Auto || $this === self::Manual;
    }

    /** A human decided (the sync never overrides it). */
    public function isHumanDecision(): bool
    {
        return $this === self::Manual || $this === self::External;
    }
}
