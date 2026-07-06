<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

/**
 * Optional hook to map assignee emails from imports to application users.
 */
interface TaskImportUserResolverInterface
{
    public function resolveByEmail(string $email): ?object;
}
