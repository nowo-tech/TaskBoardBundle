<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Since 3.3.0 (audit ZCFv2-L1): makes the next sync of a board read every task again — the connection's watermark is
 * cleared (the next run reads the whole list) and every task's stored remote date is reset (no task is skipped as
 * unchanged). *Import it again* on the Custom fields page calls it through {@see TaskCustomFieldManager::clearTombstone()},
 * so the field and its values come back on the next sync, not only on the tasks that changed since. Registered only with
 * `sync.enabled`.
 *
 * Runs inside the caller's write unit, on the caller's manager (one transaction with the tombstone's removal). Audit
 * ZCFv3-L1: it first locks the board's connection row and refuses (`sync_running`) while a sync of the board holds the
 * run lock (a live heartbeat, as {@see SyncStore::acquireLock()} judges it): a
 * running sync would cache the field as deleted and write over the reset. A sync that starts meanwhile takes its run
 * lock on that row, so it waits for this commit and then reads every task. Lock order: the connection row, the board's
 * sync items, the tombstone (the caller's flush). The UPDATEs bypass the identity map; the caller's unit holds no sync rows.
 *
 * @internal
 */
final readonly class SyncNativeFieldReread
{
    public function __construct(
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @throws TaskCustomFieldRefusedException `sync_running`, nothing written
     */
    public function forBoard(EntityManagerInterface $entityManager, TaskBoard $board): void
    {
        $busy = $entityManager->createQuery('SELECT c.id, CASE WHEN c.runningRunId IS NOT NULL AND c.heartbeatAt IS NOT NULL AND c.heartbeatAt >= :stale THEN 1 ELSE 0 END AS busy FROM ' . TaskBoardConnection::class . ' c WHERE c.board = :board')
            ->setParameter('stale', $this->clock->now()->sub(new DateInterval(TaskBoardSyncService::STALE_AFTER)), 'datetime_immutable')
            ->setParameter('board', $board->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getArrayResult();
        foreach ($busy as $row) {
            if ((int) $row['busy'] === 1) {
                throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::SYNC_RUNNING);
            }
        }
        $entityManager->createQuery('UPDATE ' . TaskSyncItem::class . ' i SET i.remoteUpdatedAt = :zero WHERE i.connection IN (SELECT c.id FROM ' . TaskBoardConnection::class . ' c WHERE c.board = :board)')
            ->setParameter('zero', new DateTimeImmutable('@0'), 'datetime_immutable')
            ->setParameter('board', $board->getId())
            ->execute();
        $entityManager->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.watermark = NULL WHERE c.board = :board')
            ->setParameter('board', $board->getId())
            ->execute();
    }
}
