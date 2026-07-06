<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskMemberAssignerTest extends TestCase
{
    public function testUnassignRemovesMemberAndPersistsTask(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $column = new BoardColumn($board, 'To do', 0);
        $board->addColumn($column);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $column);
        $user = new class {
            public string $email = 'dev@example.com';
        };
        $member = new TaskMember($task, $user, TaskMemberRole::Assignee);
        $task->addMember($member);
        $actor = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Task $saved): bool => $saved->getMembers()->count() === 0
                && !$saved->getMembers()->contains($member)));

        $assigner = new TaskMemberAssigner($repository, new TaskChangeRecorder());
        self::assertTrue($assigner->unassign($task, $member->getId(), $actor));

        self::assertCount(1, $task->getChangeHistory());
        $entry = $task->getChangeHistory()->first();
        self::assertSame(TaskChangeType::MemberRemoved, $entry->getChangeType());
        self::assertSame('dev@example.com', $entry->getOldValue());
    }

    public function testUnassignReturnsFalseWhenMemberIsMissing(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Ship feature', new stdClass());
        $actor = new stdClass();

        $assigner = new TaskMemberAssigner($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder());
        self::assertFalse($assigner->unassign($task, 'missing-id', $actor));
    }
}
