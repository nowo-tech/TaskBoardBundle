<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Since 2.26.1 (REQ-RUNTIME-003 rule 2): keeps the memory of a long batch (remote sync pages, number backfill) flat
 * without clearing a manager the bundle does not own.
 *
 * - The bundle's private manager ({@see TaskBoardEntityManager}, `database.private_entity_manager: true`) is cleared:
 *   it holds only what TaskBoard loaded.
 * - Any other manager is the application's (`database.entity_manager`): only the TaskBoard rows leave it
 *   ({@see TaskBoardManagerRegistry::isBundleEntity()}). The application's entities stay managed, with their pending
 *   changes, and the manager is never cleared, closed or reset.
 *
 * No TaskBoard association cascades `detach`, so detaching a TaskBoard row never detaches an application entity.
 *
 * @internal
 */
final class BundleEntityDetacher
{
    public static function detach(EntityManagerInterface $entityManager): void
    {
        if ($entityManager instanceof TaskBoardEntityManager) {
            $entityManager->clear();

            return;
        }

        foreach ($entityManager->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            if (!TaskBoardManagerRegistry::isBundleEntity($class)) {
                continue;
            }
            foreach ($entities as $entity) {
                $entityManager->detach($entity);
            }
        }
    }
}
