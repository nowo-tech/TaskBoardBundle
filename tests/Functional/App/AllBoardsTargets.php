<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;

/** Since 2.3.0 (audit IC-I1): a host hook that offers too much — every board — for the controller to narrow. */
final readonly class AllBoardsTargets implements TaskBoardTargetsInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function targets(Task $task, object $user): array
    {
        return $this->entityManager->getRepository(TaskBoard::class)->findAll();
    }
}
