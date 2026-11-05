<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Dto\EraseCursor;
use Nowo\TaskBoardBundle\Dto\EraseProgress;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardErasedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function is_scalar;
use function max;
use function method_exists;
use function min;

/**
 * Since 1.25: empties or deletes a board in resumable slices (Beacon "vaciar tablero" / delete board).
 *
 * Owner rule: **deleting tasks or boards keeps all tracked time**. The manual `TaskTimeEntry` rows are detached
 * (`task` = null, snapshots kept, since 1.23.2) and TimeTrack entries are never touched: they keep their task id and
 * snapshots. Imported time is deleted only by an explicit manager action in TimeTrack, never here.
 *
 * Order: the provider connection is unlinked first (a running sync is refused by its lock and the next one cannot
 * bring tasks back), then the tasks go leaf-first (subtasks before their parents) in batches flushed one by one, each
 * announced by {@see TaskErasedEvent}; `deleteBoard()` then removes the columns and the board. A call stops after its
 * time budget and returns a cursor: call again until `done`. {@see BoardErasedEvent} is dispatched once at the end.
 */
final readonly class TaskBoardEraser
{
    /** The largest batch a call accepts. */
    public const MAX_BATCH = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?TaskBoardSyncService $syncService = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        private int $timeBudgetSeconds = 20,
    ) {
    }

    /**
     * Deletes every task of the board (time kept, detached); the board and its columns stay.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     */
    public function emptyBoard(TaskBoard $board, object $actor, int $batchSize = 200, ?EraseCursor $cursor = null): EraseProgress
    {
        return $this->erase($board, $actor, $batchSize, $cursor, false);
    }

    /**
     * Deletes every task (time kept, detached), then the columns and the board.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     */
    public function deleteBoard(TaskBoard $board, object $actor, int $batchSize = 200, ?EraseCursor $cursor = null): EraseProgress
    {
        return $this->erase($board, $actor, $batchSize, $cursor, true);
    }

    private function erase(TaskBoard $board, object $actor, int $batchSize, ?EraseCursor $cursor, bool $deleteBoard): EraseProgress
    {
        if (!$this->accessChecker->canManageBoard($actor, $board)) {
            throw new AccessDeniedException('The board may not be erased.');
        }
        $batchSize = max(1, min($batchSize, self::MAX_BATCH));
        $cursor ??= new EraseCursor();
        $boardId  = $board->getId();
        $refs     = $cursor->attachmentRefs;
        $tasks    = $cursor->tasksDeleted;
        $columns  = $cursor->columnsDeleted;
        $phase    = $cursor->phase;
        $deleted  = 0;
        $deadline = $this->clock->now()->getTimestamp() + max(1, $this->timeBudgetSeconds);

        if ($phase === EraseCursor::PHASE_TASKS) {
            $refs += $this->unlink($board, $actor);
            while (true) {
                $leaves = $this->leaves($board, $batchSize);
                if ($leaves === []) {
                    $phase = $deleteBoard ? EraseCursor::PHASE_COLUMNS : EraseCursor::PHASE_BOARD;
                    break;
                }
                foreach ($leaves as $task) {
                    $taskId = $task->getId();
                    $this->eventDispatcher?->dispatch(new TaskErasedEvent($taskId, $boardId, $task->getTitle(), $refs[$taskId] ?? [], count($task->getTimeEntries())), TaskBoardEvents::TASK_ERASED);
                    unset($refs[$taskId]);
                    $board->getTasks()->removeElement($task);
                    $task->getParent()?->getSubtasks()->removeElement($task);
                    // PreRemove detaches the manual time entries (task = null, snapshots refreshed): never deleted.
                    $this->entityManager->remove($task);
                }
                $this->entityManager->flush();
                $deleted += count($leaves);
                if ($this->clock->now()->getTimestamp() >= $deadline) {
                    break;
                }
            }
        }
        $tasks += $deleted;

        if ($deleteBoard && $phase === EraseCursor::PHASE_COLUMNS) {
            foreach ($board->getColumns()->toArray() as $column) {
                if ($column instanceof BoardColumn) {
                    $board->getColumns()->removeElement($column);
                    $this->entityManager->remove($column);
                    ++$columns;
                }
            }
            $this->entityManager->flush();
            $phase = EraseCursor::PHASE_BOARD;
        }

        if ($phase !== EraseCursor::PHASE_BOARD) {
            return new EraseProgress($deleted, $this->remaining($board), false, new EraseCursor($phase, $tasks, $columns, $refs));
        }

        if ($deleteBoard) {
            $this->entityManager->remove($board);
            $this->entityManager->flush();
        }
        $actorId = $this->idOf($actor);
        $this->eventDispatcher?->dispatch(new BoardErasedEvent($boardId, $tasks, $columns, $deleteBoard, $actorId), $deleteBoard ? TaskBoardEvents::BOARD_DELETED : TaskBoardEvents::BOARD_EMPTIED);
        $this->logger->info('TaskBoard board erased', ['bundle' => 'nowo_task_board', 'board_id' => $boardId, 'tasks' => $tasks, 'columns' => $columns, 'board_deleted' => $deleteBoard]);

        return new EraseProgress($deleted, 0, true, null);
    }

    /**
     * Unlinks the provider connection (when sync is wired) and returns the storage refs of the files the sync stored,
     * per task id, read before the unlink drops the sync attachment rows.
     *
     * @return array<string, list<string>>
     */
    private function unlink(TaskBoard $board, object $actor): array
    {
        if (!$this->syncService instanceof TaskBoardSyncService) {
            return [];
        }
        /** @var list<array{taskId: string, ref: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(a.task) AS taskId', 'a.storedRef AS ref')
            ->from(TaskSyncAttachment::class, 'a')
            ->join('a.task', 't')
            ->where('t.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getArrayResult();
        $refs = [];
        foreach ($rows as $row) {
            $refs[(string) $row['taskId']][] = (string) $row['ref'];
        }
        $this->syncService->unlink($board, $actor);

        return $refs;
    }

    /**
     * Tasks of the board without subtasks.
     *
     * @return list<Task>
     */
    private function leaves(TaskBoard $board, int $limit): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('NOT EXISTS (SELECT c.id FROM ' . Task::class . ' c WHERE c.parent = t)')
            ->setParameter('board', $board)
            ->orderBy('t.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    private function remaining(TaskBoard $board): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function idOf(object $user): ?string
    {
        $id = method_exists($user, 'getId') ? $user->getId() : null;

        return is_scalar($id) || $id instanceof Stringable ? (string) $id : null;
    }
}
