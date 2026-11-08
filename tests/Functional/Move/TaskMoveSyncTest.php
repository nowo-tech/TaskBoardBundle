<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Spec 217, FR-012 and SC-005: a synced task moved off its board is detached for good, a move waits for a running sync, and
 * a copy of a synced task is a local task.
 */
final class TaskMoveSyncTest extends SyncFunctionalTestCase
{
    public function testAMovedSyncedTaskIsNeitherUpdatedNorRecreatedByTheNextSync(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $report = $this->sync($board);
        self::assertSame([], $report->errors);
        $task   = $this->taskTitled('Design');
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        $plan = $this->planner()->plan($task, $target);
        self::assertSame(1, $plan->syncDetached);
        $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
        $this->em->clear();
        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy(['externalId' => 'a1']);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        self::assertNull($item->getTask(), 'a tombstone: the provider data stays on it');

        $this->put($this->remote('a1', 'Design, renamed remotely', 5));
        $this->sync($board);
        $this->em->clear();
        self::assertSame([], $this->tasksOf($board), 'not recreated on the source');
        $moved = $this->tasksOf($target);
        self::assertCount(1, $moved);
        self::assertSame('Design', $moved[0]->getTitle(), 'not updated');
    }

    public function testAMoveIsRefusedWhileASyncRunsOnEitherBoard(): void
    {
        $board  = $this->board('Linked', ['To do', 'Done']);
        $target = $this->board('Local', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $this->em->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = :run WHERE c.id = :id')->execute(['run' => 'run-1', 'id' => $connection->getId()]);
        $this->em->clear();
        $this->refreshOwner();
        $task   = $this->taskTitled('Design');
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);
        $plan = $this->planner()->plan($task, $target);

        try {
            $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
            self::fail('A running sync must refuse the move.');
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame('sync_running', $exception->reason);
        }
        $local = $this->task($target, 'Local task');
        $back  = $this->planner()->plan($local, $task->getBoard());
        try {
            $this->mover()->move($local, $task->getBoard(), [], $back->fingerprint, $this->owner);
            self::fail('A running sync on the target must refuse the move too.');
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame('sync_running', $exception->reason);
        }
        self::assertSame($board->getId(), $this->taskTitled('Design')->getBoard()->getId());
    }

    public function testACopyOfASyncedTaskIsLocal(): void
    {
        $board = $this->board('Linked', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $task = $this->taskTitled('Design');

        $copier = self::getContainer()->get(TaskCopier::class);
        self::assertInstanceOf(TaskCopier::class, $copier);
        self::assertTrue($copier->preview($task)->synced);
        $copy = $copier->copy($task, new TaskCopyOptions(title: 'Design (copy)'), $this->owner);

        self::assertNull($this->em->getRepository(TaskSyncItem::class)->findOneBy(['task' => $copy->task]));
        self::assertCount(0, $copy->task->getLinks());
        self::assertInstanceOf(Task::class, $copy->task);
    }

    private function planner(): TaskMovePlanner
    {
        $service = self::getContainer()->get(TaskMovePlanner::class);
        self::assertInstanceOf(TaskMovePlanner::class, $service);

        return $service;
    }

    private function mover(): TaskMover
    {
        $service = self::getContainer()->get(TaskMover::class);
        self::assertInstanceOf(TaskMover::class, $service);

        return $service;
    }
}
