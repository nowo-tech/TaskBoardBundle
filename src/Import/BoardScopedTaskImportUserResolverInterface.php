<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `BoardAwareTaskImportUserResolverInterface` (the 2.14 interface, removed in 3.0): a
 * {@see TaskImportUserResolverInterface} told the board the user would join (imports, provider syncs), as a
 * {@see TaskBoardSnapshot}. When the service implements it,
 * {@see self::resolveByEmailOnBoard()} is called instead of resolveByEmail().
 */
interface BoardScopedTaskImportUserResolverInterface extends TaskImportUserResolverInterface
{
    public function resolveByEmailOnBoard(string $email, TaskBoardSnapshot $board): ?object;
}
