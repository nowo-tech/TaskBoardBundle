<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;

use function array_diff_key;
use function array_flip;
use function spl_object_id;

/**
 * Since 2.16.0 (spec 234, FR-018): bumps {@see Task::getRevision()} once per flush for every task whose own fields
 * changed (its position, total time and `updatedAt` aside: a drag of another card renumbers its siblings, a timer adds
 * time, neither is an edit), and for every task that gained or lost an assignee or watcher. A new task keeps 1.
 *
 * Stateless: everything it reads is the flushing unit of work (worker mode).
 *
 * @internal
 */
final readonly class TaskRevisionListener
{
    /** Fields whose change alone is no new revision. */
    public const IGNORED_FIELDS = ['position', 'totalTimeSeconds', 'updatedAt', 'revision'];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $work    = $manager->getUnitOfWork();
        $ignored = array_flip(self::IGNORED_FIELDS);
        $tasks   = [];
        foreach ($work->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Task && array_diff_key($work->getEntityChangeSet($entity), $ignored) !== []) {
                $tasks[spl_object_id($entity)] = $entity;
            }
        }
        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if (!$entity instanceof TaskMember) {
                continue;
            }
            $task = $entity->getTask();
            if (!$work->isScheduledForInsert($task) && !$work->isScheduledForDelete($task) && $work->isInIdentityMap($task)) {
                $tasks[spl_object_id($task)] = $task;
            }
        }
        if ($tasks === []) {
            return;
        }
        $metadata = $manager->getClassMetadata(Task::class);
        foreach ($tasks as $task) {
            $task->bumpRevision();
            $work->recomputeSingleEntityChangeSet($metadata, $task);
        }
    }
}
