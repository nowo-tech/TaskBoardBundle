<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.18.0 (Beacon spec 234, FR-032): which two boards a dependency may join — the same board, or two boards of the
 * same folder (same tenant). Any other pair is refused (`LINK_002` in the Task API). Stateless.
 */
final class TaskBoardDependencyScope
{
    public function allows(TaskBoard $one, TaskBoard $other): bool
    {
        if ($one->getId() === $other->getId()) {
            return true;
        }
        $folder = $one->getFolderId();

        return $folder !== null && $folder === $other->getFolderId() && $one->getTenantKey() === $other->getTenantKey();
    }
}
