<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;

final readonly class DoctrineOrmTaskCommentRepository implements TaskCommentRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(TaskComment $comment): void
    {
        $this->entityManager->persist($comment);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function findById(string $id): ?TaskComment
    {
        return $this->entityManager->find(TaskComment::class, $id);
    }

    public function findByTask(Task $task): array
    {
        /** @var list<TaskComment> $comments */
        $comments = $this->entityManager->createQueryBuilder()
            ->select('c', 'a')
            ->from(TaskComment::class, 'c')
            ->join('c.author', 'a')
            ->where('c.task = :task')
            ->setParameter('task', $task)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function countByTasks(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        /** @var list<array{task: string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.task) AS task', 'COUNT(c.id) AS total')
            ->from(TaskComment::class, 'c')
            ->where('c.task IN (:tasks)')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('tasks', $taskIds)
            ->groupBy('c.task')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['task']] = (int) $row['total'];
        }

        return $counts;
    }
}
