<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use PHPUnit\Framework\TestCase;
use stdClass;

use function defined;

final class TaskBoardEventTest extends TestCase
{
    public function testBoardListQueryEventCanOverrideList(): void
    {
        $subject = new stdClass();
        $board   = new TaskBoard('Demo', 'demo', new stdClass());
        $event   = new BoardListQueryEvent($subject);

        self::assertNull($event->getSubjectId(), 'a subject without an id');
        self::assertNull($event->getOverrideList());

        $event->overrideList([$board]);
        self::assertSame([$board], $event->getOverrideList());
    }

    public function testTaskAccessCheckEventGrantDenyAndReadOnly(): void
    {
        $subject = new stdClass();
        $task    = new Task(new TaskBoard('Demo', 'demo', new stdClass()), 'Work', new stdClass());
        $event   = new TaskAccessCheckEvent($subject, $task);

        self::assertSame($subject, $event->getSubject());
        self::assertSame($task->getId(), $event->getTaskId());
        self::assertFalse($event->isGranted());
        self::assertFalse($event->isDenied());
        self::assertFalse($event->isReadOnly());

        $event->grant();
        self::assertTrue($event->isGranted());
        self::assertFalse($event->isDenied());

        $event->deny();
        self::assertTrue($event->isDenied());
        self::assertFalse($event->isGranted());

        $event->markReadOnly();
        self::assertTrue($event->isReadOnly());
    }

    public function testBoardAccessCheckEventGrantAndDeny(): void
    {
        $subject = new stdClass();
        $board   = new TaskBoard('Demo', 'demo', new stdClass());
        $event   = new BoardAccessCheckEvent($subject, $board, true);

        self::assertSame($subject, $event->getSubject());
        self::assertSame($board->getId(), $event->getBoardId());
        self::assertTrue($event->isDefaultGranted());
        self::assertFalse($event->isGranted());
        self::assertFalse($event->isDenied());
        self::assertFalse((new BoardAccessCheckEvent($subject, $board))->isDefaultGranted());

        $event->grant();
        self::assertTrue($event->isGranted());
        self::assertFalse($event->isDenied());

        $event->deny();
        self::assertTrue($event->isDenied());
        self::assertFalse($event->isGranted());
    }

    public function testTaskBoardEventsConstantsAreStable(): void
    {
        self::assertSame('nowo_task_board.board_list_query', TaskBoardEvents::BOARD_LIST_QUERY);
        self::assertSame('nowo_task_board.task_access_check', TaskBoardEvents::TASK_ACCESS_CHECK);
        // Since 3.0.0 the never-dispatched constants (deprecated since 2.10.0) are gone.
        foreach (['BOARD_LIST_RESULT', 'TASK_LIST_QUERY', 'TASK_LIST_RESULT', 'TASK_READ_ONLY_RESOLVE', 'MEMBER_LIST_QUERY'] as $removed) {
            self::assertFalse(defined(TaskBoardEvents::class . '::' . $removed), $removed);
        }
    }
}
