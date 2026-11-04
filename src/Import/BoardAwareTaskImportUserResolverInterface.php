<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.23: a {@see TaskImportUserResolverInterface} that is told the board the user would join (imports, provider
 * syncs). When the service implements it, {@see self::resolveByEmailForBoard()} is called instead of resolveByEmail().
 */
interface BoardAwareTaskImportUserResolverInterface extends TaskImportUserResolverInterface
{
    public function resolveByEmailForBoard(string $email, TaskBoard $board): ?object;
}
