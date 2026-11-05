<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;

/**
 * Since 1.25 (audit FA-L2 / EX-D2): erasure helper for the task snapshots of **detached** manual time entries.
 *
 * Since 1.23.2 deleting a task keeps its time entries, detached, with a copy of the task title, board id and external
 * id. The title is free text and may hold personal data, so a host with retention or erasure duties clears it here.
 * The time itself (minutes, date, description, user) is never deleted and the board id snapshot stays (reports and
 * access checks of the host rely on it); only `task_title_snapshot` and `task_external_id` are cleared. Entries still
 * attached to a live task are never touched (the live title is the task's own).
 */
final readonly class TaskTimeEntrySnapshotPurger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Detached entries (of one board when given) that still carry a title or external id snapshot. */
    public function countWithSnapshots(?string $boardId = null, ?DateTimeImmutable $loggedBefore = null): int
    {
        return (int) $this->scoped($this->entityManager->createQueryBuilder()->select('COUNT(e.id)')->from(TaskTimeEntry::class, 'e'), $boardId, $loggedBefore)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Clears the title and external id snapshots of detached entries (of one board, logged before a date, when given);
     * returns the number of entries changed. One bulk UPDATE: entries already loaded in the entity manager keep their
     * old values until they are reloaded.
     */
    public function purge(?string $boardId = null, ?DateTimeImmutable $loggedBefore = null): int
    {
        $query = $this->scoped(
            $this->entityManager->createQueryBuilder()->update(TaskTimeEntry::class, 'e')->set('e.taskTitleSnapshot', ':none')->set('e.taskExternalId', ':none')->setParameter('none', null),
            $boardId,
            $loggedBefore,
        );

        return (int) $query->getQuery()->execute();
    }

    private function scoped(QueryBuilder $builder, ?string $boardId, ?DateTimeImmutable $loggedBefore): QueryBuilder
    {
        $builder->where('e.task IS NULL')->andWhere('(e.taskTitleSnapshot IS NOT NULL OR e.taskExternalId IS NOT NULL)');
        if ($boardId !== null) {
            $builder->andWhere('e.boardIdSnapshot = :board')->setParameter('board', $boardId);
        }
        if ($loggedBefore instanceof DateTimeImmutable) {
            $builder->andWhere('e.loggedAt < :before')->setParameter('before', $loggedBefore);
        }

        return $builder;
    }
}
