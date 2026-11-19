<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search\SearchKit;

use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\TaskBoardBundle\Bridge\SearchKit\BoardSearchDefinition;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.25.0: SearchKit's provider contract for the `boards` group.
 */
final class BoardSearchContractTest extends TaskBoardSearchKitTestCase
{
    protected function definition(): EntitySearchDefinition
    {
        return $this->definitionService(BoardSearchDefinition::class);
    }

    protected function seed(int|string $tenant, string $text): void
    {
        $board = new TaskBoard($text, 'board-' . ++$this->seeded, $this->users[self::VIEWER_A]);
        $board->setTenantKey((string) $tenant);
        $this->em->persist($board);
        $this->em->flush();
    }
}
