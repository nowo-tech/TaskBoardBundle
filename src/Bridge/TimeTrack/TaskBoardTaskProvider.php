<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskAccessGuard;
use Nowo\TaskBoardBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TaskBoardTaskProvider implements TaskProviderInterface
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskAccessGuard $accessGuard,
    ) {
    }

    public function findForUser(string $taskId, UserInterface $user): ?TaskReference
    {
        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task || !$this->accessGuard->canTrack($user, $task)) {
            return null;
        }

        return $this->toReference($task);
    }

    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array
    {
        $tasks = $this->taskRepository->findTrackableForUser(
            UserIdResolver::getId($user),
            $query->search,
            $query->limit,
            $query->offset,
        );

        return array_map($this->toReference(...), $tasks);
    }

    public function canUserTrack(UserInterface $user, string $taskId): bool
    {
        $task = $this->taskRepository->findById($taskId);

        return $task instanceof Task && $this->accessGuard->canTrack($user, $task);
    }

    private function toReference(Task $task): TaskReference
    {
        $board = $task->getBoard();

        return new TaskReference(
            $task->getId(),
            $task->getTitle(),
            $board->getId(),
            $board->getName(),
        );
    }
}
