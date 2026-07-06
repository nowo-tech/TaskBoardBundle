<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;

final readonly class DoctrineOrmTaskChangeHistoryRepository implements TaskChangeHistoryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TaskChangeHistory $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function findByTask(Task $task): array
    {
        /** @var list<TaskChangeHistory> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(TaskChangeHistory::class, 'h')
            ->where('h.task = :task')
            ->setParameter('task', $task)
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
