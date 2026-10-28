<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskLinkAttacherObjectTest extends TestCase
{
    public function testAttachesAHostObjectOnceAndDetachesIt(): void
    {
        $task       = new Task(new TaskBoard('Board', 'board', new stdClass()), 'Fix crash', new stdClass());
        $user       = new stdClass();
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('save');
        $attacher = new TaskLinkAttacher($repository, new GitLabMergeRequestLinkParser(), new TaskChangeRecorder());

        $link = $attacher->attachObject($task, 'issue', '12', $user);
        self::assertSame(TaskLinkType::HostObject, $link->getLinkType());
        self::assertSame($link, $attacher->attachObject($task, 'issue', '12', $user, 'Again'));
        $labelled = $attacher->attachObject($task, 'issue', '13', $user, 'BEACON-13');
        self::assertCount(2, $task->getLinks());

        $history = $task->getChangeHistory()->toArray();
        self::assertSame(TaskChangeType::LinkAdded, $history[0]->getChangeType());
        self::assertSame('issue #12', $history[0]->getNewValue());
        self::assertSame('BEACON-13', $history[1]->getNewValue());

        self::assertTrue($attacher->detachObject($task, 'issue', '12', $user));
        self::assertFalse($attacher->detachObject($task, 'issue', '12', $user));
        self::assertSame([$labelled], array_values($task->getLinks()->toArray()));
        $removed = $task->getChangeHistory()->last();
        self::assertSame(TaskChangeType::LinkRemoved, $removed->getChangeType());
        self::assertSame('issue #12', $removed->getOldValue());
        self::assertSame('issue:12', $removed->getContext());
    }

    public function testDetachIgnoresOtherLinks(): void
    {
        $task = new Task(new TaskBoard('Board', 'board', new stdClass()), 'Docs', new stdClass());
        $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.com'));
        $attacher = new TaskLinkAttacher($this->createMock(TaskRepositoryInterface::class), new GitLabMergeRequestLinkParser(), new TaskChangeRecorder());

        self::assertFalse($attacher->detachObject($task, 'issue', '1', new stdClass()));
        self::assertCount(1, $task->getLinks());
    }
}
