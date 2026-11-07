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
use Nowo\TaskBoardBundle\Exception\InvalidEraseCursorException;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Nowo\TaskBoardBundle\Security\EraseCursorSigner;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Stringable;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function bin2hex;
use function count;
use function is_scalar;
use function max;
use function method_exists;
use function min;
use function random_bytes;

/**
 * Since 1.25: empties or deletes a board in resumable slices (Beacon "vaciar tablero" / delete board).
 *
 * Owner rule: **deleting tasks, boards or users keeps all tracked time**. The manual `TaskTimeEntry` rows are detached
 * (`task` = null, snapshots kept, since 1.23.2) and TimeTrack entries are never touched: they keep their task id and
 * snapshots. Imported time is deleted only by an explicit manager action in TimeTrack, never here.
 *
 * Order: the provider connection is unlinked first (a running sync is refused by its lock and the next one cannot
 * bring tasks back), then the tasks go leaf-first (subtasks before their parents) in batches flushed one by one, each
 * announced by {@see TaskErasedEvent}; `deleteBoard()` then removes the columns and the board. A call stops after its
 * time budget and returns a cursor: call again until `done`. {@see BoardErasedEvent} is dispatched once at the end.
 *
 * The returned cursor is signed ({@see EraseCursorSigner}, audit FP-L3) for the board, the actor and the operation with
 * the application secret (`%kernel.secret%` in the container). On resume an unsigned or altered cursor, or one of
 * another board, actor or operation, is refused with {@see InvalidEraseCursorException} before anything is touched.
 * Keep the cursor server-side (e.g. a Messenger message) all the same.
 *
 * Run id, expiry and replay (audit FP-I9). The first call (`$cursor` = null) creates a random run id, kept by every
 * cursor of the run and reported by {@see BoardErasedEvent::$runId}. Each cursor carries its issue time (the eraser's
 * clock) and is refused once older than `$cursorTtlSeconds` (default 1 hour). A cursor of a finished run is refused
 * without keeping state: a call never returns an unfinished cursor while the board has no task left, and every cursor
 * of the task phase names an anchor task that was still on the board when it was issued. Once the run is over the
 * anchor is gone (or, after `deleteBoard()`, the board is gone), so a replayed cursor cannot add its old counts to a
 * new run's {@see BoardErasedEvent}. A host that deletes the anchor task between two slices only has to start again.
 *
 * The HMAC key is a subkey derived from the secret, never the raw `kernel.secret` (audit FP-I10).
 */
final readonly class TaskBoardEraser
{
    /** The largest batch a call accepts. */
    public const MAX_BATCH = 1000;

    private EraseCursorSigner $cursorSigner;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?TaskBoardSyncService $syncService = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        private int $timeBudgetSeconds = 20,
        #[SensitiveParameter]
        ?string $cursorSecret = null,
        private int $cursorTtlSeconds = 3600,
    ) {
        // Without a secret (manual construction), cursors resume only through this instance.
        $this->cursorSigner = new EraseCursorSigner($cursorSecret !== null && $cursorSecret !== '' ? $cursorSecret : bin2hex(random_bytes(32)));
    }

    /**
     * Deletes every task of the board (time kept, detached); the board and its columns stay.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     * @throws InvalidEraseCursorException when `$cursor` is not the signed cursor of this board, actor and operation
     */
    public function emptyBoard(TaskBoard $board, object $actor, int $batchSize = 200, ?EraseCursor $cursor = null): EraseProgress
    {
        return $this->erase($board, $actor, $batchSize, $cursor, false);
    }

    /**
     * Deletes every task (time kept, detached), then the columns and the board.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     * @throws InvalidEraseCursorException when `$cursor` is not the signed cursor of this board, actor and operation
     */
    public function deleteBoard(TaskBoard $board, object $actor, int $batchSize = 200, ?EraseCursor $cursor = null): EraseProgress
    {
        return $this->erase($board, $actor, $batchSize, $cursor, true);
    }

    /**
     * Since 2.2.0 (spec 213): deletes one task and its subtasks, leaf-first through Doctrine (a database cascade on the
     * parent would skip the `PreRemove` that keeps their time). Time is kept, detached. Returns how many were deleted.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     * @throws TaskLinkedToProviderException when the task or a subtask follows a provider: nothing is deleted
     */
    public function eraseTask(Task $task, object $actor): int
    {
        $board = $task->getBoard();
        if (!$this->accessChecker->canManageBoard($actor, $board)) {
            throw new AccessDeniedException('The task may not be erased.');
        }
        $levels = [[$task]];
        while (($next = $this->subtasksOf($levels[array_key_last($levels)])) !== []) {
            $levels[] = $next;
        }
        foreach ($levels as $level) {
            $this->refuseLinked($level);
        }

        $boardId = $board->getId();
        $deleted = 0;
        foreach (array_reverse($levels) as $level) {
            foreach ($level as $erased) {
                $this->eventDispatcher?->dispatch(new TaskErasedEvent($erased->getId(), $boardId, $erased->getTitle(), [], count($erased->getTimeEntries())), TaskBoardEvents::TASK_ERASED);
                $board->getTasks()->removeElement($erased);
                $erased->getParent()?->getSubtasks()->removeElement($erased);
                $this->entityManager->remove($erased);
                ++$deleted;
            }
            $this->entityManager->flush();
        }
        $this->logger->info('TaskBoard task erased', ['bundle' => 'nowo_task_board', 'board_id' => $boardId, 'tasks' => $deleted]);

        return $deleted;
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<Task>
     */
    private function subtasksOf(array $tasks): array
    {
        /** @var list<Task> $subtasks */
        $subtasks = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.parent IN (:parents)')
            ->setParameter('parents', $tasks)
            ->getQuery()
            ->getResult();

        return $subtasks;
    }

    /**
     * @param list<Task> $tasks
     */
    private function refuseLinked(array $tasks): void
    {
        if (!$this->syncService instanceof TaskBoardSyncService) {
            return;
        }
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.task) AS id')
            ->from(TaskSyncItem::class, 'i')
            ->where('i.task IN (:tasks)')
            ->setParameter('tasks', $tasks)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();
        if (isset($rows[0])) {
            throw TaskLinkedToProviderException::forTask((string) $rows[0]['id']);
        }
    }

    private function erase(TaskBoard $board, object $actor, int $batchSize, ?EraseCursor $cursor, bool $deleteBoard): EraseProgress
    {
        if (!$this->accessChecker->canManageBoard($actor, $board)) {
            throw new AccessDeniedException('The board may not be erased.');
        }
        $batchSize = max(1, min($batchSize, self::MAX_BATCH));
        $boardId   = $board->getId();
        $actorId   = $this->idOf($actor);
        $now       = $this->clock->now()->getTimestamp();
        $cursor    = $cursor instanceof EraseCursor ? $this->resume($board, $actorId, $cursor, $deleteBoard, $now) : new EraseCursor(runId: bin2hex(random_bytes(16)));
        $runId     = $cursor->runId;
        $refs      = $cursor->attachmentRefs;
        $tasks     = $cursor->tasksDeleted;
        $columns   = $cursor->columnsDeleted;
        $phase     = $cursor->phase;
        $deleted   = 0;
        $deadline  = $now + max(1, $this->timeBudgetSeconds);
        $remaining = 0;

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
                // Out of time: stop, unless the board is empty (finish now: no unfinished cursor of an empty board).
                if ($this->clock->now()->getTimestamp() >= $deadline && ($remaining = $this->remaining($board)) > 0) {
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
            $next = new EraseCursor($phase, $tasks, $columns, $refs, null, $runId, $this->clock->now()->getTimestamp(), $this->anchor($board));

            return new EraseProgress($deleted, $remaining, false, $this->cursorSigner->sign($next, $boardId, $actorId, $deleteBoard));
        }

        if ($deleteBoard) {
            $this->entityManager->remove($board);
            $this->entityManager->flush();
        }
        $this->eventDispatcher?->dispatch(new BoardErasedEvent($boardId, $tasks, $columns, $deleteBoard, $actorId, $runId), $deleteBoard ? TaskBoardEvents::BOARD_DELETED : TaskBoardEvents::BOARD_EMPTIED);
        $this->logger->info('TaskBoard board erased', ['bundle' => 'nowo_task_board', 'board_id' => $boardId, 'tasks' => $tasks, 'columns' => $columns, 'board_deleted' => $deleteBoard, 'run_id' => $runId]);

        return new EraseProgress($deleted, 0, true, null);
    }

    /**
     * Verifies a cursor handed back by the caller (audit FP-L3) and returns the state to resume from:
     * - the signature must match this board, actor and operation, and the cursor's phase, counts and refs;
     * - it must carry its run id and issue time and be at most `$cursorTtlSeconds` old (audit FP-I9);
     * - `emptyBoard()` has no column phase: `PHASE_COLUMNS` resumes as `PHASE_BOARD` (it could never return `done`);
     * - a phase past the tasks is allowed only once the board has no task left and no ref is pending;
     * - a task-phase cursor's anchor task must still be on the board, otherwise its run is over (audit FP-I9);
     * - refs are kept only for task ids that still belong to the board.
     *
     * @throws InvalidEraseCursorException
     */
    private function resume(TaskBoard $board, ?string $actorId, EraseCursor $cursor, bool $deleteBoard, int $now): EraseCursor
    {
        $this->cursorSigner->verify($cursor, $board->getId(), $actorId, $deleteBoard);
        if ($cursor->runId === null || $cursor->issuedAt === null) {
            throw InvalidEraseCursorException::incomplete();
        }
        if ($now - $cursor->issuedAt > max(1, $this->cursorTtlSeconds)) {
            throw InvalidEraseCursorException::expired();
        }
        $phase = !$deleteBoard && $cursor->phase === EraseCursor::PHASE_COLUMNS ? EraseCursor::PHASE_BOARD : $cursor->phase;
        if ($phase !== EraseCursor::PHASE_TASKS && ($cursor->attachmentRefs !== [] || $this->remaining($board) > 0)) {
            throw InvalidEraseCursorException::phaseNotAllowed($cursor->phase);
        }
        if ($phase === EraseCursor::PHASE_TASKS && !$this->isOnBoard($board, $cursor->anchorTaskId)) {
            throw InvalidEraseCursorException::finished();
        }

        return new EraseCursor($phase, $cursor->tasksDeleted, $cursor->columnsDeleted, $this->refsOnBoard($board, $cursor->attachmentRefs), null, $cursor->runId);
    }

    /**
     * The id of a task still on the board (the anchor of the next cursor), or null when it has none.
     */
    private function anchor(TaskBoard $board): ?string
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->setParameter('board', $board)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return isset($rows[0]) ? (string) $rows[0]['id'] : null;
    }

    private function isOnBoard(TaskBoard $board, ?string $taskId): bool
    {
        return $taskId !== null && $this->refsOnBoard($board, [$taskId => []]) !== [];
    }

    /**
     * Keeps the refs of the task ids that still belong to the board.
     *
     * @param array<string, list<string>> $refs
     *
     * @return array<string, list<string>>
     */
    private function refsOnBoard(TaskBoard $board, array $refs): array
    {
        if ($refs === []) {
            return [];
        }
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('board', $board)
            ->setParameter('ids', array_keys($refs))
            ->getQuery()
            ->getArrayResult();

        return array_intersect_key($refs, array_flip(array_map(static fn (array $row): string => (string) $row['id'], $rows)));
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
