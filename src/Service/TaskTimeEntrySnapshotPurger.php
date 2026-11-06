<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;

use function count;

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

    /**
     * Since 1.27.0 (owner rule): call before deleting a user. Their manual time stays; the entries keep their name, or
     * lose it with `$forgetName` (erasure). Deleting the user without this keeps the name stored at logging time.
     *
     * @return int the entries detached
     */
    public function detachUser(object $user, bool $forgetName = false): int
    {
        $label = Text::cut(UserLabel::of($user), Task::USER_NAME_MAX);
        // Rows written before 1.27 have no name yet: they get the current one first.
        $this->entityManager->createQuery('UPDATE ' . TaskTimeEntry::class . ' e SET e.userName = :label WHERE e.user = :user AND e.userName IS NULL')
            ->setParameter('label', $label)->setParameter('user', $user)->execute();
        $count = (int) $this->entityManager->createQuery('UPDATE ' . TaskTimeEntry::class . ' e SET e.user = NULL' . ($forgetName ? ', e.userName = NULL' : '') . ' WHERE e.user = :user')
            ->setParameter('user', $user)->execute();
        // The bulk updates bypass the identity map: loaded entries follow.
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[TaskTimeEntry::class] ?? [] as $entry) {
            if ($entry instanceof TaskTimeEntry && SyncUser::same($entry->getUser(), $user)) {
                $entry->detachUser($forgetName);
            }
        }
        $this->entityManager->flush();

        return $count;
    }

    /**
     * Since 1.27.0: clears the kept name after the user is gone (the row no longer leads to them).
     *
     * @return int the entries changed
     */
    public function forgetUserName(string $name): int
    {
        return (int) $this->entityManager->createQuery('UPDATE ' . TaskTimeEntry::class . ' e SET e.userName = NULL WHERE e.user IS NULL AND e.userName = :name')
            ->setParameter('name', $name)->execute();
    }

    /**
     * Since 1.27.0: fills the name of entries logged before 1.27, a page at a time; run it after the upgrade (repeat
     * until it returns 0). Until then, deleting such a user leaves the entry without a name.
     *
     * @return int the entries filled
     */
    public function backfillUserNames(int $batchSize = 500): int
    {
        /** @var list<TaskTimeEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()->select('e')->from(TaskTimeEntry::class, 'e')
            ->where('e.user IS NOT NULL')->andWhere('e.userName IS NULL')->setMaxResults($batchSize)->getQuery()->getResult();
        foreach ($entries as $entry) {
            $entry->backfillUserName();
        }
        $this->entityManager->flush();

        return count($entries);
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
