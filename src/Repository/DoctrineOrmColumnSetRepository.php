<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use SortDirection;

/**
 * Doctrine ORM implementation for column sets (since 1.11).
 */
final readonly class DoctrineOrmColumnSetRepository implements ColumnSetRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(ColumnSet $set): void
    {
        $this->entityManager->persist($set);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function remove(ColumnSet $set): void
    {
        $this->entityManager->remove($set);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function findById(string $id): ?ColumnSet
    {
        $set = $this->entityManager->find(ColumnSet::class, $id);

        return $set instanceof ColumnSet ? $set : null;
    }

    public function findByScope(?string $scopeKey): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(ColumnSet::class, 's')
            ->orderBy('s.name', SortDirection::Ascending);

        if ($scopeKey === null) {
            $builder->where('s.scopeKey IS NULL');
        } else {
            $builder->where('s.scopeKey = :scopeKey')->setParameter('scopeKey', $scopeKey);
        }

        /** @var list<ColumnSet> $sets */
        $sets = $builder->getQuery()->getResult();

        return $sets;
    }
}
