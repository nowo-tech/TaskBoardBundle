<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use RuntimeException;

/**
 * Twin replay (TASK_BOARD_TEST_PORTS=twin): finds the bundle entity behind a snapshot, through the bundle's own
 * repositories, so a test stub written against the deprecated interface answers through a twin-only adapter.
 */
final readonly class TwinLookup
{
    public function __construct(
        private TaskBoardRepositoryInterface $boards,
        private TaskRepositoryInterface $tasks,
        private TaskCommentRepositoryInterface $comments,
        private ManagerRegistry $registry,
    ) {
    }

    public function board(string $id): TaskBoard
    {
        return $this->boards->findById($id) ?? throw new RuntimeException('No board ' . $id);
    }

    public function task(string $id): Task
    {
        return $this->tasks->findById($id) ?? throw new RuntimeException('No task ' . $id);
    }

    public function comment(string $id): TaskComment
    {
        return $this->comments->findById($id) ?? throw new RuntimeException('No comment ' . $id);
    }

    public function change(string $id): TaskChangeHistory
    {
        return $this->registry->getManagerForClass(TaskChangeHistory::class)?->find(TaskChangeHistory::class, $id) ?? throw new RuntimeException('No history row ' . $id);
    }

    public function directory(string $id): TaskSyncDirectory
    {
        return $this->registry->getManagerForClass(TaskSyncDirectory::class)?->find(TaskSyncDirectory::class, $id) ?? throw new RuntimeException('No directory ' . $id);
    }
}
