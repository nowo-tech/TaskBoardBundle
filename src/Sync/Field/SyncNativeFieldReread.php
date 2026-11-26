<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;

/**
 * Since 3.3.0 (audit ZCFv2-L1): makes the next sync of a board read every task again — the connection's watermark is
 * cleared (the next run reads the whole list) and every task's stored remote date is reset (no task is skipped as
 * unchanged). *Import it again* on the Custom fields page calls it through {@see TaskCustomFieldManager::clearTombstone()},
 * so the field and its values come back on the next sync, not only on the tasks that changed since. Registered only with
 * `sync.enabled`.
 *
 * Runs inside the caller's write unit, on the caller's manager (one transaction with the tombstone's removal). Lock order:
 * the board's sync items, then its connection row (a sync page writes items; its run lock and cursor are written to the
 * connection row outside the page). The UPDATEs bypass the identity map; the caller's unit holds no sync rows.
 *
 * @internal
 */
final readonly class SyncNativeFieldReread
{
    public function forBoard(EntityManagerInterface $entityManager, TaskBoard $board): void
    {
        $entityManager->createQuery('UPDATE ' . TaskSyncItem::class . ' i SET i.remoteUpdatedAt = :zero WHERE i.connection IN (SELECT c.id FROM ' . TaskBoardConnection::class . ' c WHERE c.board = :board)')
            ->setParameter('zero', new DateTimeImmutable('@0'), 'datetime_immutable')
            ->setParameter('board', $board->getId())
            ->execute();
        $entityManager->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.watermark = NULL WHERE c.board = :board')
            ->setParameter('board', $board->getId())
            ->execute();
    }
}
