<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\OAuthConnectionStartInterface;

/**
 * Since 3.1.0: the bundle's own entity-typed seam of {@see OAuthConnectionStartInterface}, implemented by the
 * credential source's gate. Hosts implement the framework-free interface.
 *
 * @internal
 */
interface OAuthConnectionStarterInterface
{
    public function connectUrl(TaskBoard $board, string $provider, string $returnTo): ?string;
}
