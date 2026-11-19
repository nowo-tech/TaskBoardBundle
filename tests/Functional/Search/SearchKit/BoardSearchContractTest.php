<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search\SearchKit;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\TaskBoardBundle\Bridge\SearchKit\BoardSearchDefinition;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.25.0: SearchKit's provider contract for the `boards` group. Hidden rows are boards BOARD_ACCESS_CHECK denies;
 * archived rows are archived boards.
 */
final class BoardSearchContractTest extends TaskBoardSearchKitTestCase
{
    protected function definition(): EntitySearchDefinition
    {
        return $this->definitionService(BoardSearchDefinition::class);
    }

    protected function seed(int|string $tenant, string $text): int|string
    {
        return $this->board($tenant, $text)->getId();
    }

    protected function seedHidden(int|string $tenant, string $text): int|string
    {
        $id                = $this->board($tenant, $text)->getId();
        $this->denied[$id] = true;

        return $id;
    }

    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        $board = $this->board($tenant, $text);
        $board->archive();
        $this->em->flush();

        return $board->getId();
    }

    protected function reference(int|string $id): SearchReference
    {
        return $this->referenceFor('nowo_task_board_board', 'boardId', $id);
    }

    private function board(int|string $tenant, string $text): TaskBoard
    {
        $board = new TaskBoard($text, 'board-' . ++$this->seeded, $this->users[self::VIEWER_A]);
        $board->setTenantKey((string) $tenant);
        $this->em->persist($board);
        $this->em->flush();
        $this->tenantOfRow[$board->getId()] = (string) $tenant;

        return $board;
    }
}
