<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.18.0 (FR-034): a status set that would reopen completed tasks asks the host's limit first.
 */
final class StatusSetLimitTest extends SyncFunctionalTestCase
{
    public function testAReopeningOverTheLimitRefusesTheSet(): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);
        $sets = self::getContainer()->get(FolderStatusSets::class);
        self::assertInstanceOf(FolderStatusSets::class, $sets);

        $board = $this->board('Board', ['To do', 'Done']);
        $done  = (new Task(board: $board, title: 'Done', creator: $this->owner, column: $board->getColumns()->last() ?: null))->markCompleted();
        $this->em->persist($done);
        $this->em->flush();
        $folder = $service->createFolder($this->owner, null, 'Epic');
        $service->moveBoardToFolder($this->owner, $board->getId(), $folder->id);

        TestSyncLimitChecker::$refuseTasks = true;
        try {
            $sets->setStatusSet($this->owner, $folder->id, [new BoardColumnSpec('To do', ColumnType::Open), new BoardColumnSpec('Done', ColumnType::Custom)]);
            self::fail('over the limit');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::QUOTA, $refused->reason);
        } finally {
            TestSyncLimitChecker::reset();
        }
    }
}
