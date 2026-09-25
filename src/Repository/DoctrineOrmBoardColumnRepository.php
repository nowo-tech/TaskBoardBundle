<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\BoardColumn;

final readonly class DoctrineOrmBoardColumnRepository implements BoardColumnRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(BoardColumn $column): void
    {
        $this->entityManager->persist($column);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function saveAll(array $columns): void
    {
        foreach ($columns as $column) {
            $this->entityManager->persist($column);
        }

        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }
}
