<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Port\Snapshots;
use PHPUnit\Framework\TestCase;

final class SnapshotsTest extends TestCase
{
    use PortFixtures;

    public function testBoard(): void
    {
        $board    = $this->board();
        $snapshot = Snapshots::board($board);

        self::assertSame($board->getId(), $snapshot->id);
        self::assertSame('roadmap', $snapshot->slug);
        self::assertSame('Roadmap', $snapshot->name);
        self::assertSame('acme', $snapshot->tenantKey);
        self::assertSame('u-7', $snapshot->creatorId);
        self::assertSame($board->getCreatorName(), $snapshot->creatorName);
        self::assertFalse($snapshot->archived);
        self::assertSame($board->getCreatedAt(), $snapshot->createdAt);
    }

    public function testTask(): void
    {
        $task     = $this->task();
        $snapshot = Snapshots::task($task);

        self::assertSame($task->getId(), $snapshot->id);
        self::assertSame($task->getBoard()->getId(), $snapshot->boardId);
        self::assertSame('acme', $snapshot->tenantKey);
        self::assertSame('Ship it', $snapshot->title);
        self::assertNull($snapshot->number);
        self::assertSame($task->getColumn()?->getId(), $snapshot->columnId);
        self::assertSame($task->getParent()?->getId(), $snapshot->parentId);
        self::assertSame('u-7', $snapshot->creatorId);
        self::assertSame($task->getCreatorName(), $snapshot->creatorName);
        self::assertSame(TaskPriority::High, $snapshot->priority);
        self::assertFalse($snapshot->completed);
        self::assertFalse($snapshot->archived);
        self::assertNull($snapshot->startAt);
        self::assertSame($task->getDueAt(), $snapshot->dueAt);
        self::assertSame($task->getTags(), $snapshot->tags);
        self::assertNull($snapshot->externalId);
        self::assertSame($task->getCreatedAt(), $snapshot->createdAt);
        self::assertSame($task->getUpdatedAt(), $snapshot->updatedAt);
    }

    public function testTaskWithoutColumnParentOrCreator(): void
    {
        $snapshot = Snapshots::task(new Task($this->board(), 'Bare', null));

        self::assertNull($snapshot->columnId);
        self::assertNull($snapshot->parentId);
        self::assertNull($snapshot->creatorId);
    }

    public function testComment(): void
    {
        $comment  = $this->comment();
        $snapshot = Snapshots::comment($comment);

        self::assertSame($comment->getId(), $snapshot->id);
        self::assertSame($comment->getTask()->getId(), $snapshot->taskId);
        self::assertSame($comment->getTask()->getBoard()->getId(), $snapshot->boardId);
        self::assertSame('u-7', $snapshot->authorId);
        self::assertSame($comment->getAuthorName(), $snapshot->authorName);
        self::assertSame('Looks good', $snapshot->body);
        self::assertSame('rule:42', $snapshot->cause);
        self::assertFalse($snapshot->deleted);
        self::assertSame($comment->getCreatedAt(), $snapshot->createdAt);
        self::assertNull($snapshot->editedAt);
    }

    public function testChange(): void
    {
        $change   = $this->change();
        $snapshot = Snapshots::change($change);

        self::assertSame($change->getId(), $snapshot->id);
        self::assertSame($change->getTask()->getId(), $snapshot->taskId);
        self::assertSame($change->getTask()->getBoard()->getId(), $snapshot->boardId);
        self::assertSame('u-7', $snapshot->userId);
        self::assertSame($change->getUserName(), $snapshot->userName);
        self::assertSame(TaskChangeType::Title, $snapshot->changeType);
        self::assertSame('old', $snapshot->oldValue);
        self::assertSame('new', $snapshot->newValue);
        self::assertSame('title', $snapshot->context);
        self::assertSame('rule:7', $snapshot->cause);
        self::assertSame($change->getCreatedAt(), $snapshot->createdAt);
    }

    public function testDirectory(): void
    {
        $directory = $this->directory();
        $snapshot  = Snapshots::directory($directory);

        self::assertSame($directory->getId(), $snapshot->id);
        self::assertSame('clickup', $snapshot->provider);
        self::assertSame('project:1', $snapshot->scopeKey);
        self::assertSame('ws-1', $snapshot->workspaceId);
    }
}
