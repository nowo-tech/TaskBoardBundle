<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Status;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Event\UnmatchedColumnsResolvedEvent;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function usort;

/**
 * Since 1.28.0 (TB-SYNC-4): `unmatchedColumns: delete`. Acts once per connection, marked on the connection before any
 * deletion, at the end of the first import when it completed without error, across all its slices. Unmatched means no
 * provider status maps to the column, whatever it holds; the fallback column counts as mapped. Only an empty column
 * goes: any task row counts (archived, completed and soft-deleted too), with the check and the delete in one
 * transaction. A column holding tasks stays and is listed in the run report; a board keeps at least one column; tasks
 * never move; later syncs never delete a column. Same permission as *Delete column*, logged the same way.
 *
 * It runs on DBAL so a failure cannot close the EntityManager of a run that has already imported everything: whatever
 * goes wrong, the columns stay and the report says so.
 */
final readonly class UnmatchedColumnsCleaner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /** Whether the run is the one that must decide (the choice was `delete`, and nothing decided yet). */
    public function isDue(SyncRunContext $ctx): bool
    {
        return !$ctx->options->dryRun && $ctx->connection->getUnmatchedColumns() === TaskBoardConnection::UNMATCHED_DELETE && !$ctx->connection->isUnmatchedColumnsResolved();
    }

    public function resolve(SyncRunContext $ctx): void
    {
        // The marker goes first: a crash below leaves columns, never a second chance at a later sync.
        $firstImport = !$ctx->connection->getLastSuccessAt() instanceof DateTimeImmutable;
        $ctx->connection->markUnmatchedColumnsResolved($this->clock->now());
        $this->entityManager->flush();
        if (!$firstImport || $ctx->pagingStopped) {
            $ctx->warn('unmatched_columns_incomplete');

            return;
        }
        if (!$this->accessChecker->canEditBoard($ctx->actor, $ctx->board)) {
            $ctx->warn('unmatched_columns_denied');

            return;
        }
        $statuses = $this->statuses($ctx);
        if ($statuses === null) {
            // Without the provider's statuses nothing can be called unmatched.
            $ctx->warn('unmatched_columns_unknown');

            return;
        }
        $this->clean($ctx, $this->unmatched($ctx, $statuses));
    }

    /**
     * @return list<RemoteStatus>|null
     */
    private function statuses(SyncRunContext $ctx): ?array
    {
        if (!$ctx->provider instanceof SourceProviderInterface) {
            return null;
        }
        try {
            $statuses = $ctx->provider->listStatuses($ctx->credential, new RemoteSource($ctx->connection->getLocation(), $ctx->connection->getContainerLabel()));
        } catch (Throwable) {
            return null;
        }

        return $statuses === [] ? null : $statuses;
    }

    /**
     * The board's columns no status maps to, by position. A status follows the writer's rules: the dialog's map (a skipped
     * status maps to nothing), then the same name.
     *
     * @param list<RemoteStatus> $statuses
     *
     * @return list<BoardColumn>
     */
    private function unmatched(SyncRunContext $ctx, array $statuses): array
    {
        $map     = $ctx->connection->getStatusColumnMap();
        $columns = array_values($ctx->board->getColumns()->toArray());
        usort($columns, static fn (BoardColumn $a, BoardColumn $b): int => $a->getPosition() <=> $b->getPosition());
        $matched = [(string) $ctx->connection->fallbackColumnId() => true];
        foreach ($statuses as $status) {
            $target = StatusColumns::target($map, $status->name);
            if ($target !== null) {
                $matched[$target] = true;
                continue;
            }
            foreach ($columns as $column) {
                if (StatusColumns::normalize($column->getName()) === StatusColumns::normalize($status->name)) {
                    $matched[$column->getId()] = true;
                }
            }
        }

        return array_values(array_filter($columns, static fn (BoardColumn $column): bool => !isset($matched[$column->getId()])));
    }

    /**
     * @param list<BoardColumn> $unmatched
     */
    private function clean(SyncRunContext $ctx, array $unmatched): void
    {
        $left    = $ctx->board->getColumns()->count();
        $deleted = [];
        $kept    = [];
        foreach ($unmatched as $column) {
            if ($left <= 1) {
                $kept[] = $column->getName();
                $ctx->warn('unmatched_column_kept_last', ['%column%' => $column->getName()]);
                continue;
            }
            try {
                $gone = $this->deleteIfEmpty($column);
            } catch (Throwable $exception) {
                $this->logger->error('TaskBoard sync: unmatched column not deleted', ['bundle' => 'nowo_task_board', 'board_id' => $ctx->board->getId(), 'column_id' => $column->getId(), 'exception' => $exception::class]);
                $kept[] = $column->getName();
                $ctx->warn('unmatched_column_kept_failed', ['%column%' => $column->getName()]);
                continue;
            }
            if (!$gone) {
                $kept[] = $column->getName();
                $ctx->warn('unmatched_column_kept_tasks', ['%column%' => $column->getName()]);
                continue;
            }
            --$left;
            $deleted[$column->getId()] = $column->getName();
            $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => 'column_delete', 'board_id' => $ctx->board->getId(), 'column_id' => $column->getId(), 'origin' => 'sync_unmatched_columns']);
            $ctx->board->removeColumn($column);
        }
        if ($deleted !== []) {
            $position = 0;
            $columns  = array_values($ctx->board->getColumns()->toArray());
            usort($columns, static fn (BoardColumn $a, BoardColumn $b): int => $a->getPosition() <=> $b->getPosition());
            foreach ($columns as $column) {
                $column->setPosition($position++);
            }
            $this->entityManager->flush();
            $ctx->warn('unmatched_columns_deleted', ['%count%' => (string) count($deleted), '%columns%' => implode(', ', $deleted)]);
        }
        $ctx->count('columns.unmatchedDeleted', count($deleted));
        $ctx->count('columns.unmatchedKept', count($kept));
        $this->eventDispatcher?->dispatch(new UnmatchedColumnsResolvedEvent($ctx->board->getId(), $ctx->connection->getId(), $ctx->runId, $deleted, $kept), TaskBoardEvents::SYNC_UNMATCHED_COLUMNS_RESOLVED);
    }

    /**
     * Check and delete in one transaction, under `SELECT ... FOR UPDATE` on the column row. A foreign key check takes a
     * shared lock on that row (`FOR KEY SHARE` on PostgreSQL), so a task written at the same time waits, then is seen, or
     * is refused because the column is gone: `ON DELETE SET NULL` can never empty a column here (audit HI-M1). A no-op
     * `UPDATE` would not do: on PostgreSQL it takes `FOR NO KEY UPDATE`, which does not conflict with `FOR KEY SHARE`.
     * SQLite has no such clause and one writer at a time. DBAL sees rows no Doctrine filter hides.
     */
    private function deleteIfEmpty(BoardColumn $column): bool
    {
        $connection = $this->entityManager->getConnection();
        $columns    = $this->entityManager->getClassMetadata(BoardColumn::class);
        $tasks      = $this->entityManager->getClassMetadata(Task::class);
        $table      = $connection->quoteIdentifier($columns->getTableName());
        $id         = $connection->quoteIdentifier($columns->getSingleIdentifierColumnName());
        $lock       = $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';

        // @igor-ignore - Doctrine runs this static closure at once and does not keep it.
        return (bool) $connection->transactional(static function (Connection $db) use ($column, $tasks, $table, $id, $lock): bool {
            $db->fetchOne(sprintf('SELECT %s FROM %s WHERE %s = ?%s', $id, $table, $id, $lock), [$column->getId()]);
            $held = (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $db->quoteIdentifier($tasks->getTableName()), $db->quoteIdentifier($tasks->getSingleAssociationJoinColumnName('column'))), [$column->getId()]);
            if ($held > 0) {
                return false;
            }
            $db->executeStatement(sprintf('DELETE FROM %s WHERE %s = ?', $table, $id), [$column->getId()]);

            return true;
        });
    }
}
