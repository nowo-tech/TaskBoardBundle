<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskAccessGuard;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function array_values;

/**
 * Since 2.28.0: TimeTrack's task directory (TimeTrackBundle >= 1.23, the framework-free twin of its task provider;
 * REQ-ARCH-001 rule 3). The service `nowo_task_board.task_provider` (TimeTrack's `task_provider` option) is this class.
 * Same answers as {@see TaskBoardTaskProvider}: the user is the {@see TimeTrackUserRef} TimeTrack builds from the
 * security user; the access check needs that user object, and a reference without one (or without an id) sees no task.
 */
final readonly class TaskBoardTaskDirectory implements TaskDirectoryInterface
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskAccessGuard $accessGuard,
    ) {
    }

    public function findTaskFor(string $taskId, TimeTrackUserRef $user): ?TaskReference
    {
        $task = $this->trackable($taskId, $user);

        return $task instanceof Task ? self::toReference($task) : null;
    }

    public function listTrackableFor(TimeTrackUserRef $user, TaskListQuery $query): array
    {
        if ($user->id === null) {
            return [];
        }

        return array_values(array_map(self::toReference(...), $this->taskRepository->findTrackableForUser($user->id, $query->search, $query->limit, $query->offset)));
    }

    public function canTrackTask(TimeTrackUserRef $user, string $taskId): bool
    {
        return $this->trackable($taskId, $user) instanceof Task;
    }

    /** The task, when the user may track time on it. */
    private function trackable(string $taskId, TimeTrackUserRef $user): ?Task
    {
        $securityUser = $user->user();
        if (!$securityUser instanceof UserInterface) {
            return null;
        }
        $task = $this->taskRepository->findById($taskId);

        return $task instanceof Task && $this->accessGuard->canTrack($securityUser, $task) ? $task : null;
    }

    private static function toReference(Task $task): TaskReference
    {
        $board = $task->getBoard();

        return new TaskReference($task->getId(), $task->getTitle(), $board->getId(), $board->getName());
    }
}
