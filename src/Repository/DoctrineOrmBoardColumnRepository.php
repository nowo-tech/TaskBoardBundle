<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;

final readonly class DoctrineOrmBoardColumnRepository implements BoardColumnRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(BoardColumn $column): void
    {
        $this->entityManager->persist($column);
        $this->entityManager->flush();
    }

    public function saveAll(array $columns): void
    {
        foreach ($columns as $column) {
            $this->entityManager->persist($column);
        }

        $this->entityManager->flush();
    }
}
