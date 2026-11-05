<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a person's role in the remote workspace.
 */
enum RemoteRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Member = 'member';
    case Guest  = 'guest';

    /** Owners and admins may read everyone's time (ClickUp). */
    public function isAdmin(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
