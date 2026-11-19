<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search\SearchKit;

use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\TaskBoardBundle\Bridge\SearchKit\TaskSearchDefinition;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.25.0: SearchKit's provider contract for the `tasks` group.
 */
final class TaskSearchContractTest extends TaskBoardSearchKitTestCase
{
    protected function definition(): EntitySearchDefinition
    {
        return $this->definitionService(TaskSearchDefinition::class);
    }

    protected function seed(int|string $tenant, string $text): void
    {
        $board = $this->tenantBoard($tenant);
        $this->em->persist(new Task($board, $text, $this->users[self::VIEWER_A]));
        $this->em->flush();
    }
}
