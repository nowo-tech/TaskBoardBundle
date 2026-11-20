<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Throwable;

use function spl_object_id;

/**
 * Since 2.26.1 (audit WA-2): the application rows a TaskBoard write wrote through the application's entity manager
 * (e.g. rows an application `onFlush` listener added) inside a transaction that is then rolled back on purpose (a sync
 * dry run). Without it those entities would stay managed with values, or ids, that the rollback undid, and a later
 * edit would update nothing.
 *
 * Only for a shared manager: the bundle's private manager is cleared after the rollback anyway.
 *
 * @internal
 */
final class RolledBackHostRows
{
    /** @var array<int, object> */
    private array $inserted = [];

    /** @var array<int, object> */
    private array $updated = [];

    private function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Starts recording the application rows the manager inserts or updates; null for the bundle's private manager.
     */
    public static function start(EntityManagerInterface $entityManager): ?self
    {
        if ($entityManager instanceof TaskBoardEntityManager) {
            return null;
        }
        $recorder = new self($entityManager);
        $entityManager->getEventManager()->addEventListener([Events::postPersist, Events::postUpdate], $recorder);

        return $recorder;
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->recorded($args->getObjectManager(), $entity)) {
            $this->inserted[spl_object_id($entity)] = $entity;
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->recorded($args->getObjectManager(), $entity) && !isset($this->inserted[spl_object_id($entity)])) {
            $this->updated[spl_object_id($entity)] = $entity;
        }
    }

    /**
     * Stops recording (always call it, rolled back or not).
     */
    public function stop(): void
    {
        $this->entityManager->getEventManager()->removeEventListener([Events::postPersist, Events::postUpdate], $this);
    }

    /**
     * After the rollback: the rows it inserted leave the manager and the rows it updated are read again. A row that
     * cannot be read again leaves the manager too.
     */
    public function forget(): void
    {
        $this->stop();
        foreach ($this->inserted as $entity) {
            if ($this->entityManager->contains($entity)) {
                $this->entityManager->detach($entity);
            }
        }
        foreach ($this->updated as $entity) {
            if (!$this->entityManager->contains($entity)) {
                continue;
            }
            try {
                $this->entityManager->refresh($entity);
            } catch (Throwable) {
                $this->entityManager->detach($entity);
            }
        }
        $this->inserted = [];
        $this->updated  = [];
    }

    private function recorded(object $manager, object $entity): bool
    {
        return $manager === $this->entityManager && !TaskBoardManagerRegistry::isBundleEntity($entity::class);
    }
}
