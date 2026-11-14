<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCompletedEvent;
use Nowo\TaskBoardBundle\Event\TaskReopenedEvent;
use Nowo\TaskBoardBundle\Event\TaskStatusChangedEvent;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;

use function array_key_last;

/**
 * Since 2.19.0 (audit PW-H1 / PW-L5): the tasks a column change moves or completes as a side effect (a retype, a shared
 * status set, a board configuration) get what a move by hand gets: a `column` and a `completed` history row each, and
 * TASK_STATUS_CHANGED / TASK_COMPLETED / TASK_REOPENED. The caller takes a {@see self::snapshot()} of the board before it
 * changes the columns, then {@see self::record()} before its flush, and dispatches the returned events after its commit.
 *
 * Without an actor (an import, a sync, a worker) there is no history row (a row needs its author) and no completion event
 * (it carries the actor); TASK_STATUS_CHANGED still goes out. Stateless (REQ-RUNTIME-002).
 *
 * @internal
 */
final readonly class TaskColumnFollow
{
    public function __construct(
        private ?TaskChangeRecorder $recorder = null,
        private ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * Every task of the board (read from the database when a manager is at hand: the board's collection may not know them
     * all): the task, its column and whether it is completed, by task id.
     *
     * @return array<string, array{Task, ?BoardColumn, bool}>
     */
    public function snapshot(TaskBoard $board): array
    {
        $tasks = $board->getTasks()->toArray();
        if ($this->entityManager instanceof EntityManagerInterface && $this->entityManager->isOpen()) {
            /** @var list<Task> $tasks */
            $tasks = $this->entityManager->createQueryBuilder()->select('t')->from(Task::class, 't')
                ->where('IDENTITY(t.board) = :board')->setParameter('board', $board->getId())->getQuery()->getResult();
        }
        $before = [];
        foreach ($tasks as $task) {
            $before[$task->getId()] = [$task, $task->getColumn(), $task->isCompleted()];
        }

        return $before;
    }

    /**
     * History rows for the tasks of `$before` whose column or completion changed (no flush).
     *
     * @param array<string, array{Task, ?BoardColumn, bool}> $before
     *
     * @return list<array{object, string}> the events to dispatch after the commit, with their names
     */
    public function record(TaskBoard $board, array $before, ?object $actor, ColumnTransitionVia $via): array
    {
        $events  = [];
        $columns = ColumnTypes::ordered($board);
        $last    = $columns === [] ? null : $columns[array_key_last($columns)];
        foreach ($before as [$task, $from, $wasCompleted]) {
            $to         = $task->getColumn();
            $moved      = $from?->getId() !== $to?->getId();
            $completion = $wasCompleted !== $task->isCompleted();
            if (!$moved && !$completion) {
                continue;
            }
            if ($actor !== null) {
                $this->recorder?->recordIfChanged($task, $actor, TaskChangeType::Column, $from, $to);
                $this->recorder?->recordIfChanged($task, $actor, TaskChangeType::Completed, $wasCompleted, $task->isCompleted());
            }
            if ($moved && $to instanceof BoardColumn) {
                $target   = Snapshots::column($to, $to->getId() === $last?->getId(), $columns);
                $events[] = [new TaskStatusChangedEvent(
                    Snapshots::task($task),
                    $from instanceof BoardColumn ? Snapshots::column($from, $from->getId() === $last?->getId(), $columns) : null,
                    $target,
                    ActorId::of($actor),
                    $via,
                    $target->type,
                ), TaskBoardEvents::TASK_STATUS_CHANGED];
            }
            if ($completion && $actor !== null) {
                $events[] = $task->isCompleted()
                    ? [new TaskCompletedEvent($task, $actor), TaskBoardEvents::TASK_COMPLETED]
                    : [new TaskReopenedEvent($task, $actor), TaskBoardEvents::TASK_REOPENED];
            }
        }

        return $events;
    }
}
