<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;

use function array_map;
use function sort;

/**
 * Since 2.2.0 (spec 213): a task that follows a provider is not erased on its own, as the next sync would bring it back.
 */
final class SyncEraseTaskTest extends SyncFunctionalTestCase
{
    public function testASyncedTaskIsNotErasedOnItsOwnNorIsItsParent(): void
    {
        $board = $this->board();
        $this->put($this->remote('s1', 'Synced', 1));
        $this->sync($board);
        $fresh  = $this->freshBoard($board);
        $local  = $this->task($fresh, 'Local');
        $parent = $this->task($fresh, 'Local parent');
        $synced = $this->taskTitled('Synced');
        $synced->setParent($parent);
        $this->em->flush();
        $eraser = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);

        foreach ([$synced, $parent] as $refused) {
            try {
                $eraser->eraseTask($refused, $this->owner);
                self::fail('TaskLinkedToProviderException expected');
            } catch (TaskLinkedToProviderException) {
            }
        }
        self::assertSame(1, $eraser->eraseTask($local, $this->owner), 'a local task on a linked board goes');

        $this->em->clear();
        $titles = array_map(static fn (Task $task): string => $task->getTitle(), $this->em->getRepository(Task::class)->findAll());
        sort($titles);
        self::assertSame(['Local parent', 'Synced'], $titles);
    }
}
