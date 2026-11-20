<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_pop;
use function is_iterable;
use function is_object;
use function spl_object_id;

/**
 * Since 2.27.0 (REQ-RUNTIME-003 rule 2): keeps the memory of a long batch (remote sync pages, number backfill) flat
 * without clearing a manager the bundle does not own.
 *
 * - The bundle's private manager ({@see TaskBoardEntityManager}, `database.private_entity_manager: true`) is cleared:
 *   it holds only what TaskBoard loaded.
 * - Any other manager is the application's (`database.entity_manager`). Only the TaskBoard rows leave it, and only those
 *   that no managed application entity still reaches (audit WA-1). A TaskBoard row an application entity references
 *   (a to-one value, or an element of a collection) stays managed, and so does every TaskBoard row it reaches in turn
 *   through its TaskBoard associations (a kept task keeps its board and column). Otherwise the application's next flush
 *   would take the detached row for a new entity. Uninitialized proxies are kept but never loaded; of an uninitialized
 *   collection only the elements added to it since it was loaded are walked (audit WA-6: `add()` and `set()` do not
 *   initialize it), without reading the database.
 *
 * The application's entities are never detached, and the manager is never cleared, closed or reset. When the rows to
 * keep cannot be worked out (a mapping or a property that cannot be read), nothing is detached and a warning is
 * logged: memory grows for that batch, and the application's entities stay consistent.
 *
 * No TaskBoard association cascades `detach`, so detaching a TaskBoard row never detaches an application entity.
 *
 * @internal
 */
final class BundleEntityDetacher
{
    /**
     * @param bool $refreshKept after a rollback (dry run): the kept TaskBoard rows are read again from the database
     *                          first, so none of them keeps a value or a collection element the rollback undid
     */
    public static function detach(EntityManagerInterface $entityManager, ?LoggerInterface $logger = null, bool $refreshKept = false): void
    {
        if ($entityManager instanceof TaskBoardEntityManager) {
            $entityManager->clear();

            return;
        }

        try {
            $keep = self::reachable($entityManager);
            if ($refreshKept) {
                foreach ($keep as $entity) {
                    if ($entityManager->contains($entity) && !$entityManager->getUnitOfWork()->isUninitializedObject($entity)) {
                        $entityManager->refresh($entity);
                    }
                }
                // The refreshed rows hold the database's values and lazy collections now: some rows may no longer be reached.
                $keep = self::reachable($entityManager);
            }
        } catch (Throwable $exception) {
            $logger?->warning('TaskBoard: the TaskBoard rows were left in the application\'s entity manager: the rows its entities still reference could not be worked out ({exception}).', [
                'bundle'    => 'nowo_task_board',
                'exception' => $exception::class,
            ]);

            return;
        }

        foreach ($entityManager->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            if (!TaskBoardManagerRegistry::isBundleEntity($class)) {
                continue;
            }
            foreach ($entities as $entity) {
                if (!isset($keep[spl_object_id($entity)])) {
                    $entityManager->detach($entity);
                }
            }
        }
    }

    /**
     * The TaskBoard rows the managed application entities reach (directly, or through other kept TaskBoard rows).
     *
     * @return array<int, object> keyed by object id
     */
    private static function reachable(EntityManagerInterface $entityManager): array
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $pending    = [];
        $roots      = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if (!TaskBoardManagerRegistry::isBundleEntity($class)) {
                foreach ($entities as $entity) {
                    $roots[] = $entity;
                }
            }
        }
        foreach ($roots as $root) {
            if (!TaskBoardManagerRegistry::isBundleEntity($root::class)) {
                self::collect($entityManager, $root, $pending);
            }
        }

        $keep = [];
        while (($entity = array_pop($pending)) !== null) {
            $id = spl_object_id($entity);
            if (isset($keep[$id])) {
                continue;
            }
            $keep[$id] = $entity;
            // An uninitialized proxy is kept as it is: reading its properties would load it.
            if (!$unitOfWork->isUninitializedObject($entity)) {
                self::collect($entityManager, $entity, $pending);
            }
        }

        return $keep;
    }

    /**
     * Adds the TaskBoard objects an entity holds in its TaskBoard associations: to-one values, the elements of
     * initialized collections and the elements added to uninitialized ones.
     *
     * @param list<object> $pending
     */
    private static function collect(EntityManagerInterface $entityManager, object $entity, array &$pending): void
    {
        $metadata = $entityManager->getClassMetadata($entity::class);
        foreach ($metadata->associationMappings as $field => $mapping) {
            if (!TaskBoardManagerRegistry::isBundleEntity($mapping->targetEntity)) {
                continue;
            }
            $value = $metadata->getFieldValue($entity, $field);
            if ($value instanceof PersistentCollection && !$value->isInitialized()) {
                // Since 2.27.0 (audit WA-6): add() and set() do not initialize the collection; the elements added since it
                // was loaded are in the wrapped collection, read without any database access.
                $value = $value->unwrap();
            }
            foreach ($mapping->isToOne() || !is_iterable($value) ? [$value] : $value as $related) {
                if (is_object($related)) {
                    $pending[] = $related;
                }
            }
        }
    }
}
