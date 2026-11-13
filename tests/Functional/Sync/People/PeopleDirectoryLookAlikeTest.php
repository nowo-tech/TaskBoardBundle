<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\People\InvalidMappingException;
use Nowo\TaskBoardBundle\Tests\Functional\FoldsKeyColumns;

use function sprintf;

/**
 * Audit OM TB-M1 (REQ-SEC-006): on `task_board_sync_directories` keys that still fold (before the UPGRADING ALTER), a
 * workspace whose directory the database finds only as a look-alike is refused instead of breaking the unique key.
 */
final class PeopleDirectoryLookAlikeTest extends PeopleFunctionalTestCase
{
    use FoldsKeyColumns;

    public function testALookAlikeWorkspaceDirectoryIsRefused(): void
    {
        $this->foldKeyColumns($this->em);
        $this->owner = $this->user('owner@example.com');
        $board       = $this->board();
        $service     = $this->directories();
        $directory   = $service->directoryForDraft($board, 'clickup', 'ws-a');
        self::assertSame($directory->getId(), $service->directoryForDraft($board, 'clickup', 'ws-a')->getId());

        try {
            $service->directoryForDraft($board, 'clickup', 'WS-A');
            self::fail('a look-alike workspace never gets a second directory');
        } catch (InvalidMappingException $exception) {
            self::assertSame('workspace_look_alike', $exception->reason);
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->em->getClassMetadata(TaskSyncDirectory::class)->getTableName())));
    }
}
