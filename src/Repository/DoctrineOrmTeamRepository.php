<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Team;

final readonly class DoctrineOrmTeamRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Team $team): void
    {
        $this->entityManager->persist($team);
        $this->entityManager->flush();
    }
}
