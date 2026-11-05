<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use RuntimeException;

/**
 * Since 1.25: the actor may not manage a board of the directory (routes answer 404).
 */
final class PeopleMappingDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The people of this connection cannot be managed by this user.');
    }
}
