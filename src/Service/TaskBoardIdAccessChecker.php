<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\User\TaskBoardUserLocator;
use Nowo\TaskBoardBundle\ValueObject\UserRef;

use function count;

/**
 * Since 2.29.0: the bundle's {@see TaskBoardAccessInterface}. Loads the user and the resource by id (exact ids) and asks
 * the same checkers as the pages. Stateless (FrankenPHP worker mode): nothing is kept between calls.
 */
final readonly class TaskBoardIdAccessChecker implements TaskBoardAccessInterface
{
    public const MAX_IDS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardUserLocator $users,
        private TaskBoardAccessCheckerInterface $accessChecker,
        private TaskBoardResourceAccessChecker $resources,
    ) {
    }

    public function canAccess(UserRef $user): bool
    {
        return $this->user($user) !== null;
    }

    public function canViewBoard(UserRef $user, string $boardId): bool
    {
        return $this->onBoard($user, $boardId, fn (object $actor, TaskBoard $board): bool => $this->resources->canViewBoard($actor, $board));
    }

    public function canEditBoard(UserRef $user, string $boardId): bool
    {
        return $this->onBoard($user, $boardId, fn (object $actor, TaskBoard $board): bool => $this->resources->canEditBoard($actor, $board));
    }

    public function canManageBoard(UserRef $user, string $boardId): bool
    {
        return $this->onBoard($user, $boardId, fn (object $actor, TaskBoard $board): bool => $this->resources->canManageBoard($actor, $board));
    }

    public function canViewTask(UserRef $user, string $taskId): bool
    {
        return $this->onTask($user, $taskId, fn (object $actor, Task $task): bool => $this->resources->canViewTask($actor, $task));
    }

    public function canEditTask(UserRef $user, string $taskId): bool
    {
        return $this->onTask($user, $taskId, fn (object $actor, Task $task): bool => $this->resources->canEditTask($actor, $task));
    }

    public function canTrackTime(UserRef $user, string $taskId): bool
    {
        return $this->onTask($user, $taskId, fn (object $actor, Task $task): bool => $this->resources->canTrackTime($actor, $task));
    }

    public function filterBoardIds(UserRef $user, array $boardIds): array
    {
        if (count($boardIds) > self::MAX_IDS) {
            throw new InvalidArgumentException('TaskBoard: at most 500 board ids per call.');
        }
        $actor = $this->user($user);
        if ($actor === null) {
            return [];
        }
        $visible = [];
        foreach ($boardIds as $id) {
            $board = $this->board($id);
            if ($board instanceof TaskBoard && $this->resources->canViewBoard($actor, $board)) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    /** The host user behind the reference, when it passes the global gate; null otherwise. */
    public function user(UserRef $user): ?object
    {
        $actor = $this->users->find($user);

        return $actor !== null && $this->accessChecker->canAccess($actor) ? $actor : null;
    }

    /** The board with exactly this id, or null. */
    public function board(string $boardId): ?TaskBoard
    {
        $board = $boardId === '' ? null : $this->entityManager->find(TaskBoard::class, $boardId);

        return $board instanceof TaskBoard && $board->getId() === $boardId ? $board : null;
    }

    /** The task with exactly this id, or null. */
    public function task(string $taskId): ?Task
    {
        $task = $taskId === '' ? null : $this->entityManager->find(Task::class, $taskId);

        return $task instanceof Task && $task->getId() === $taskId ? $task : null;
    }

    /**
     * @param callable(object, TaskBoard): bool $decide
     */
    private function onBoard(UserRef $user, string $boardId, callable $decide): bool
    {
        $actor = $this->user($user);
        $board = $actor === null ? null : $this->board($boardId);

        return $actor !== null && $board instanceof TaskBoard && $decide($actor, $board);
    }

    /**
     * @param callable(object, Task): bool $decide
     */
    private function onTask(UserRef $user, string $taskId, callable $decide): bool
    {
        $actor = $this->user($user);
        $task  = $actor === null ? null : $this->task($taskId);

        return $actor !== null && $task instanceof Task && $decide($actor, $task);
    }
}
