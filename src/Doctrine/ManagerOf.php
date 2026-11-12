<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Since 2.15.0 (audit OC-I2): the open entity manager that holds an entity. `getManagerForClass()` alone may answer
 * another manager than the one the bundle wrote through (DoctrineBundle 3.4 with a dedicated `database.entity_manager`
 * answers the default one), and the worker-mode refresh before a decision or a write would then be skipped silently;
 * the registry's other managers are asked too. Stateless.
 *
 * @internal
 */
final class ManagerOf
{
    public static function entity(?ManagerRegistry $registry, object $entity): ?EntityManagerInterface
    {
        if (!$registry instanceof ManagerRegistry) {
            return null;
        }
        $candidates = [$registry->getManagerForClass($entity::class), ...$registry->getManagers()];
        foreach ($candidates as $manager) {
            if ($manager instanceof EntityManagerInterface && $manager->isOpen() && $manager->contains($entity)) {
                return $manager;
            }
        }

        return null;
    }
}
