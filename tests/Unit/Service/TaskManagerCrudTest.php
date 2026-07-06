<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskManagerCrudTest extends TestCase
{
    public function testUpdatePriorityPersistsChange(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        (new TaskManager($repo, new TaskChangeRecorder()))->updatePriority($task, TaskPriority::Urgent, $user);
        self::assertSame(TaskPriority::Urgent, $task->getPriority());
    }

    public function testCreatePersistsNewTask(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $col   = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($col);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findByBoard')->willReturn([]);
        $repo->expects(self::once())->method('save');

        $task = (new TaskManager($repo, new TaskChangeRecorder()))->create(
            $board,
            new TaskFormData(title: 'New task', tags: ['api', 'api']),
            $user,
            $parent = new Task($board, 'Parent', $user),
        );

        self::assertSame('New task', $task->getTitle());
        self::assertSame($parent, $task->getParent());
        self::assertSame(['api'], $task->getTags());
        self::assertCount(1, $task->getChangeHistory());
    }

    public function testUpdatePersistsChanges(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $todo  = new BoardColumn($board, 'Todo', 0);
        $done  = new BoardColumn($board, 'Done', 1);
        $board->addColumn($todo)->addColumn($done);
        $task = new Task($board, 'Old', $user, column: $todo);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $manager = new TaskManager($repo, new TaskChangeRecorder());
        $manager->update(
            $task,
            new TaskFormData(title: 'New', description: 'Desc', priority: TaskPriority::High, columnId: $done->getId()),
            $user,
        );

        self::assertSame('New', $task->getTitle());
        self::assertSame('Done', $task->getColumn()?->getName());
    }

    public function testMoveReordersTask(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $col   = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($col);
        $task = new Task($board, 'Move me', $user, column: $col);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findByBoard')->willReturn([$task]);
        $repo->expects(self::once())->method('save');

        (new TaskManager($repo, new TaskChangeRecorder()))->move($task, $col->getId(), 5, $user);
        self::assertSame(5, $task->getPosition());
    }

    public function testTaskBoardCreatorAddsDefaultColumns(): void
    {
        $repo = $this->createMock(TaskBoardRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $board = (new TaskBoardCreator($repo))->create(
            new TaskBoardFormData(name: 'New board', slug: ''),
            new TestUser('1', 'owner@example.com'),
        );

        self::assertSame('new-board', $board->getSlug());
        self::assertCount(3, $board->getColumns());
    }

    public function testMemberAssignerAssignsUser(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);
        $actor = new TestUser('2', 'lead@example.com');

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $member = (new TaskMemberAssigner($repo, new TaskChangeRecorder()))->assign(
            $task,
            new TaskMemberFormData(user: $user, memberRole: TaskMemberRole::Assignee),
            $actor,
        );

        self::assertSame($user, $member->getUser());
        self::assertCount(1, $task->getMembers());
    }

    public function testMemberAssignerRejectsMissingUser(): void
    {
        $board = new TaskBoard('Demo', 'demo', new TestUser('1', 'a@example.com'));
        $task  = new Task($board, 'Work', new TestUser('1', 'a@example.com'));

        $this->expectException(InvalidArgumentException::class);
        (new TaskMemberAssigner($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))
            ->assign($task, new TaskMemberFormData(memberRole: TaskMemberRole::Assignee), new stdClass());
    }
}
