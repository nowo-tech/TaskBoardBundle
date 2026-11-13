<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * A host's service repository (not registered in the test kernel): only the source's container factory may answer it.
 *
 * @extends ServiceEntityRepository<AppUser>
 */
final class HostServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppUser::class);
    }
}
