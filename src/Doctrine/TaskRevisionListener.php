<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use stdClass;

use function array_diff;
use function array_diff_key;
use function array_flip;
use function array_keys;
use function count;
use function spl_object_id;

/**
 * Since 2.16.0 (spec 234, FR-018): bumps {@see Task::getRevision()} once per flush for every task whose own fields
 * changed (its position, total time and `updatedAt` aside: a drag of another card renumbers its siblings, a timer adds
 * time, neither is an edit), and for every task that gained or lost an assignee or watcher. A new task keeps 1.
 *
 * Since 2.17.0 (audit PC-L4): the database adds the 1. `onFlush` only marks the tasks this flush changes; the first
 * post-write event of a marked task inside the flush's transaction (its own `postUpdate`, or the `postPersist` /
 * `postRemove` of a member) runs `UPDATE … SET revision = revision + 1 WHERE id = ?` ({@see TaskRevisionIncrement}) and puts the value
 * written into the object and the unit of work's original data. The row is locked by that UPDATE (if the flush's own UPDATE did not lock
 * it already) until the commit, so the increment starts from the last committed revision whatever the writer loaded:
 * two writers never save the same number, and a write that rolls back takes its 1 with it. The column is
 * `updatable: false`, so no flush ever writes a revision computed in PHP.
 *
 * Since 2.18.0 (audit PS-L1) it also makes the provenance of a task's or comment's last write ({@see self::PROVENANCE_FIELDS})
 * one UPDATE.
 *
 * Stateless: everything it reads is the flushing unit of work and the task objects (worker mode).
 *
 * @internal
 */
final readonly class TaskRevisionListener
{
    /**
     * Fields whose change alone is no new revision. Since 2.18.0 the last write's provenance (`updatedBy`, `channel`,
     * `actorLabel`): it changes with every history row, also for a dependency, a link or logged time, which were no
     * new revision in 2.16.
     */
    /** Since 2.18.0: the provenance of a task's or comment's last write, always written together. */
    public const PROVENANCE_FIELDS = ['updatedBy', 'channel', 'actorLabel'];

    public const IGNORED_FIELDS = ['position', 'totalTimeSeconds', 'updatedAt', 'revision', 'updatedBy', 'channel', 'actorLabel'];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $work    = $manager->getUnitOfWork();
        $ignored = array_flip(self::IGNORED_FIELDS);
        foreach ($work->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Task || $entity instanceof TaskComment) {
                $this->writeProvenanceTogether($manager, $entity);
            }
            if (!$entity instanceof Task) {
                continue;
            }
            // Audit PG-I1: a mark a failed flush left behind never counts in this one.
            $entity->takeRevisionPending();
            if (array_diff_key($work->getEntityChangeSet($entity), $ignored) !== []) {
                $entity->markRevisionPending();
            }
        }
        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if (!$entity instanceof TaskMember) {
                continue;
            }
            $task = $entity->getTask();
            if (!$work->isScheduledForInsert($task) && !$work->isScheduledForDelete($task) && $work->isInIdentityMap($task)) {
                $task->markRevisionPending();
            }
        }
    }

    /**
     * Since 2.18.0 (audit PS-L1): the last write's provenance is one fact. When a flush changes any of `updatedBy`,
     * `channel` and `actorLabel`, the UPDATE carries all three, so two overlapping writes (each loaded before the other
     * committed) never leave one person's id next to another's token name.
     */
    private function writeProvenanceTogether(EntityManagerInterface $manager, Task|TaskComment $entity): void
    {
        $work    = $manager->getUnitOfWork();
        $missing = array_diff(self::PROVENANCE_FIELDS, array_keys($work->getEntityChangeSet($entity)));
        if ($missing === [] || count($missing) === count(self::PROVENANCE_FIELDS)) {
            return;
        }
        foreach ($missing as $field) {
            // A value no property can hold: the recomputed change set has the field, its UPDATE the current value.
            $work->setOriginalEntityProperty(spl_object_id($entity), $field, new stdClass());
        }
        $work->recomputeSingleEntityChangeSet($manager->getClassMetadata($entity::class), $entity);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Task) {
            $this->increment($args->getObjectManager(), $entity);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TaskMember) {
            $this->increment($args->getObjectManager(), $entity->getTask());
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TaskMember) {
            $this->increment($args->getObjectManager(), $entity->getTask());
        }
    }

    private function increment(EntityManagerInterface $manager, Task $task): void
    {
        if (!$task->takeRevisionPending()) {
            return;
        }
        $metadata   = $manager->getClassMetadata(Task::class);
        $connection = $manager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $quotes     = $manager->getConfiguration()->getQuoteStrategy();
        $table      = $quotes->getTableName($metadata, $platform);
        $revision   = $quotes->getColumnName('revision', $metadata, $platform);
        $id         = $quotes->getColumnName($metadata->getSingleIdentifierFieldName(), $metadata, $platform);
        $current    = TaskRevisionIncrement::next($connection, $table, $revision, $id, $task->getId());
        if ($current === null) {
            // Audit PG-L1: another request deleted the task meanwhile. As in 2.16.0 the flush goes on (the UPDATE of a
            // missing row changed nothing); the object keeps the revision it had.
            return;
        }
        $metadata->setFieldValue($task, 'revision', $current);
        $manager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($task), 'revision', $current);
    }
}
