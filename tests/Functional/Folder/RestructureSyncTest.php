<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.18.0 (FR-045): a synced task moved by the restructure service is detached for good, and no move runs while a
 * provider run is in progress on a board it touches.
 */
final class RestructureSyncTest extends SyncFunctionalTestCase
{
    public function testASyncedTaskIsDetachedAndARunningSyncRefusesTheMove(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->put($this->remote('a2', 'Build'));
        $this->sync($board);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        $service->moveTasks($this->owner, [$this->taskTitled('Design')->getId()], $target->getId(), ['To do' => $target->getColumns()->first()->getId()]);
        $this->em->clear();
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => 'a1']);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        self::assertNull($item->getTask(), 'a tombstone');

        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = :run WHERE c.id = :id')->execute(['run' => 'run-1', 'id' => $connection->getId()]);
        $this->em->clear();
        $this->refreshOwner();
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        try {
            $service->moveTasks($this->owner, [$this->taskTitled('Build')->getId()], $target->getId());
            self::fail('a run is in progress');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::SYNC_RUNNING, $refused->reason);
        }
    }
}
