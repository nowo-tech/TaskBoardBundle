<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A task write, dispatched after it is flushed (since 1.10): the task and the user who made the change. Since 2.10.0 (DDD
 * audit G2) it also carries the ids as they were at the write ({@see self::getTaskId()}, getBoardId(), getTenantKey(),
 * getTaskNumber(), getActorId()), so a host translates it without touching the entities.
 */
abstract class AbstractTaskEvent extends Event
{
    private readonly string $taskId;

    private readonly string $boardId;

    private readonly ?string $tenantKey;

    private readonly ?int $taskNumber;

    private readonly ?string $actorId;

    /**
     * @param Task $task The task, as saved
     * @param object $actor The user who made the change
     */
    public function __construct(
        private readonly Task $task,
        private readonly object $actor,
    ) {
        $board            = $task->getBoard();
        $this->taskId     = $task->getId();
        $this->boardId    = $board->getId();
        $this->tenantKey  = $board->getTenantKey();
        $this->taskNumber = $task->getNumber();
        $this->actorId    = ActorId::of($actor);
    }

    /**
     * @deprecated since 2.15.0, use {@see self::getTaskId()}, {@see self::getBoardId()}, {@see self::getTenantKey()}, {@see self::getTaskNumber()} or {@see self::getTaskSnapshot()}; removed in 3.0
     */
    public function getTask(): Task
    {
        return $this->task;
    }

    public function getActor(): object
    {
        return $this->actor;
    }

    /** Since 2.10.0. */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /** Since 2.10.0: the task's board when the event was made. */
    public function getBoardId(): string
    {
        return $this->boardId;
    }

    /** Since 2.10.0: the board's tenant key (null: none). */
    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    /** Since 2.10.0: the task's number (null with `task_numbers` off, or before it is assigned). */
    public function getTaskNumber(): ?int
    {
        return $this->taskNumber;
    }

    /** Since 2.10.0: the actor's `getId()` as a string; null when it has no scalar id. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** Since 2.15.0: the task as a framework-free snapshot, copied on each call. */
    public function getTaskSnapshot(): TaskSnapshot
    {
        return Snapshots::task($this->task);
    }
}
