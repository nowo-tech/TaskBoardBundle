<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;

final readonly class DoctrineOrmTaskChangeHistoryRepository implements TaskChangeHistoryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(TaskChangeHistory $entry): void
    {
        $this->entityManager->persist($entry);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
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
