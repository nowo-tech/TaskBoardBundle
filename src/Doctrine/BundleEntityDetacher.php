<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Since 2.27.0 (REQ-RUNTIME-003 rule 2): keeps the memory of a long batch (remote sync pages, number backfill) flat
 * without clearing a manager the bundle does not own.
 *
 * The bundle's private manager ({@see TaskBoardEntityManager}) is cleared: it holds only what TaskBoard loaded. Since
 * 3.0.0 the private manager is the only mode, so a bundle service always gets it; any other manager (a service built by
 * hand on the application's manager) is left untouched: it is never cleared, closed, reset or detached from (2.27-2.32
 * detached the TaskBoard rows no application entity referenced in that mode, a path removed with the mode).
 *
 * @internal
 */
final class BundleEntityDetacher
{
    public static function detach(EntityManagerInterface $entityManager): void
    {
        if ($entityManager instanceof TaskBoardEntityManager) {
            $entityManager->clear();
        }
    }
}
