<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Entity;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/**
 * Since 2.19.0 (Beacon spec 234): the last write's provenance on tasks, comments and history rows — set, cut to 120
 * characters, cleared, and dropped by the account erasure helpers (the label only when anonymising).
 */
final class WriteProvenanceEntityTest extends TestCase
{
    public function testATaskKeepsItsLastWriteAndForgetsIt(): void
    {
        $owner = new stdClass();
        $agent = new stdClass();
        $task  = new Task(new TaskBoard('Board', 'board', $owner), 'Task', $owner);
        self::assertNull($task->getUpdatedBy());
        self::assertNull($task->getChannel());

        $task->recordWrite($agent, new WriteProvenance(WriteChannel::Mcp, str_repeat('a', 130)));
        self::assertSame($agent, $task->getUpdatedBy());
        self::assertSame(WriteChannel::Mcp, $task->getChannel());
        self::assertSame(str_repeat('a', 120), $task->getActorLabel());

        $task->detachUpdatedBy();
        self::assertNull($task->getUpdatedBy());
        self::assertSame(str_repeat('a', 120), $task->getActorLabel(), 'kept without anonymising');
        $task->recordWrite($agent, new WriteProvenance(WriteChannel::Api, 'token'))->detachUpdatedBy(true);
        self::assertNull($task->getActorLabel());
        self::assertSame(WriteChannel::Api, $task->getChannel());

        $task->recordWrite($owner, null);
        self::assertSame($owner, $task->getUpdatedBy());
        self::assertNull($task->getChannel(), 'no provenance clears the columns');
    }

    public function testACommentAndAHistoryRowKeepTheirProvenance(): void
    {
        $owner   = new stdClass();
        $editor  = new stdClass();
        $task    = new Task(new TaskBoard('Board', 'board', $owner), 'Task', $owner);
        $comment = new TaskComment($task, $owner, 'Hi', provenance: new WriteProvenance(WriteChannel::App, 'Phone'));
        self::assertSame($owner, $comment->getUpdatedBy());
        self::assertSame(WriteChannel::App, $comment->getChannel());
        self::assertSame('Phone', $comment->getActorLabel());

        $comment->edit('Hello', []);
        self::assertSame($owner, $comment->getUpdatedBy(), 'an edit without an editor keeps the last writer');
        $comment->edit('Hello!', [], $editor, new WriteProvenance(WriteChannel::Web));
        self::assertSame($editor, $comment->getUpdatedBy());
        self::assertSame(WriteChannel::Web, $comment->getChannel());
        self::assertNull($comment->getActorLabel());
        $comment->detachUpdatedBy();
        self::assertNull($comment->getUpdatedBy());
        $comment->edit('Again', [], $editor, new WriteProvenance(WriteChannel::Api, 'token'));
        $comment->detachUpdatedBy(true);
        self::assertNull($comment->getActorLabel());

        $entry = new TaskChangeHistory($task, $owner, TaskChangeType::Title, 'a', 'b', provenance: new WriteProvenance(WriteChannel::Api, 'token'));
        self::assertSame(WriteChannel::Api, $entry->getChannel());
        self::assertSame('token', $entry->getActorLabel());
        $entry->detachUser(true);
        self::assertNull($entry->getActorLabel());
        self::assertSame(WriteChannel::Api, $entry->getChannel());
    }
}
