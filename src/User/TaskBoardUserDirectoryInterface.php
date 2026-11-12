<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\User;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2, required with `database.user_reference: id`): finds a host user by the id the
 * TaskBoard rows keep. The bundle asks it when it needs more than the id: a label, an avatar, the actor of a queued sync
 * run, a picker's choice. Return your user object (a Symfony security user for the web pages), or null when the id names
 * nobody. The bundle re-checks the answer exactly: an object whose `getId()` is not the asked id byte for byte is
 * ignored.
 */
interface TaskBoardUserDirectoryInterface
{
    public function find(string $id): ?object;
}
