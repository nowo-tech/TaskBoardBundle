<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineOrmTaskTimeEntryRepository implements TaskTimeEntryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Expose the manager for future persistence methods (keeps DI wiring valid).
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
