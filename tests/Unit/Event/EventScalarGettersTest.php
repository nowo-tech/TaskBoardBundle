<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Event;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardCreatedEvent;
use Nowo\TaskBoardBundle\Event\BoardUpdatedEvent;
use Nowo\TaskBoardBundle\Event\TaskCommentEvent;
use Nowo\TaskBoardBundle\Event\TaskCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskMemberAddedEvent;
use Nowo\TaskBoardBundle\Event\TaskMentionEvent;
use Nowo\TaskBoardBundle\Event\TaskMovedEvent;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.10.0 (DDD audit G2): the existing write events also carry scalar ids, taken when the event is made, beside the
 * entity getters they keep.
 */
final class EventScalarGettersTest extends TestCase
{
    public function testTaskEventsCarryTheIdsAsTheyWereAtTheWrite(): void
    {
        $ana   = new TestUser('7', 'ana');
        $board = new TaskBoard('Board', 'board', $ana);
        $board->setTenantKey('acme');
        $todo = new BoardColumn($board, 'To do', 0);
        $done = new BoardColumn($board, 'Done', 1);
        $task = new Task($board, 'Task', $ana);

        $created = new TaskCreatedEvent($task, $ana);
        $board->setTenantKey('other');
        self::assertSame([$task->getId(), $board->getId(), 'acme', null, '7'], [$created->getTaskId(), $created->getBoardId(), $created->getTenantKey(), $created->getTaskNumber(), $created->getActorId()]);
        self::assertSame($task, $created->getTask());
        self::assertNull((new TaskCreatedEvent($task, new stdClass()))->getActorId());

        $moved = new TaskMovedEvent($task, $ana, $todo, $done);
        self::assertSame([$todo->getId(), $done->getId()], [$moved->getFromColumnId(), $moved->getToColumnId()]);
        $none = new TaskMovedEvent($task, $ana, null, null);
        self::assertSame([null, null], [$none->getFromColumnId(), $none->getToColumnId()]);

        $comment = new TaskComment($task, $ana, 'Hi @bob');
        self::assertSame($comment->getId(), (new TaskCommentEvent($ana, $comment, TaskCommentEvent::CREATED))->getCommentId());
        $mention = new TaskMentionEvent($ana, $comment, [new TestUser('8', 'bob'), new stdClass(), new TestUser('9', 'eve')]);
        self::assertSame($comment->getId(), $mention->getCommentId());
        self::assertSame(['8', '9'], $mention->getMentionedUserIds());

        $member = new TaskMember($task, new TestUser('8', 'bob'), TaskMemberRole::Reviewer);
        $added  = new TaskMemberAddedEvent($task, $ana, $member);
        self::assertSame([$member->getId(), '8', 'reviewer'], [$added->getMemberId(), $added->getUserId(), $added->getRole()]);
    }

    public function testBoardEventsCarryTheirIds(): void
    {
        $ana   = new TestUser('7', 'ana');
        $board = new TaskBoard('Board', 'board', $ana);
        $board->setTenantKey('acme');

        foreach ([new BoardCreatedEvent($board, $ana), new BoardUpdatedEvent($board, $ana, ['name'])] as $event) {
            self::assertSame([$board->getId(), 'acme', '7'], [$event->getBoardId(), $event->getTenantKey(), $event->getActorId()]);
        }
    }
}
