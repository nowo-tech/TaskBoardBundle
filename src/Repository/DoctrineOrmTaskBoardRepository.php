<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use SortDirection;

/**
 * Doctrine ORM implementation for task boards.
 */
final readonly class DoctrineOrmTaskBoardRepository implements TaskBoardRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(TaskBoard $board): void
    {
        $this->entityManager->persist($board);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function remove(TaskBoard $board): void
    {
        $this->entityManager->remove($board);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function findById(string $id): ?TaskBoard
    {
        $board = $this->entityManager->find(TaskBoard::class, $id);

        return $board instanceof TaskBoard ? $board : null;
    }

    public function findAllActive(): array
    {
        /** @var list<TaskBoard> $boards */
        $boards = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL')
            ->orderBy('b.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $boards;
    }

    public function findBySlug(string $slug): ?TaskBoard
    {
        $board = $this->entityManager->getRepository(TaskBoard::class)->findOneBy(['slug' => $slug]);

        return $board instanceof TaskBoard ? $board : null;
    }
}
