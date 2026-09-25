<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Team;

final readonly class DoctrineOrmTeamRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(Team $team): void
    {
        $this->entityManager->persist($team);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }
}
