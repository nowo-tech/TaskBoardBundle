<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

final class RemainingCoverageTest extends TestCase
{
    public function testTaskEntityGuardsAndCollections(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user, 'Desc');
        $col   = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($col);
        $task = new Task($board, 'Work', $user, column: $col);

        $secondsBefore = $task->getTotalTimeSeconds();
        $task->addTimeSeconds(0);
        self::assertSame($secondsBefore, $task->getTotalTimeSeconds());

        $member = new TaskMember($task, $user, TaskMemberRole::Watcher);
        $task->addMember($member)->addMember($member);
        self::assertCount(1, $task->getMembers());

        $link = new TaskLink($task, TaskLinkType::Url, 'https://example.com');
        $task->addLink($link)->addLink($link);
        self::assertCount(1, $task->getLinks());

        $board->addColumn($col);
        self::assertCount(1, $board->getColumns());
        self::assertNotNull($board->getCreatedAt());
        self::assertNotNull($board->getUpdatedAt());
        self::assertSame('Desc', $board->getDescription());
        self::assertSame($user, $task->getCreator());
        self::assertNotNull($task->getUpdatedAt());
    }

    public function testEntityGettersForDocumentsDependenciesAndTimeEntries(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);
        $other = new Task($board, 'Blocked', $user);
        $dep   = new TaskDependency($task, $other, TaskDependencyType::BlockedBy);

        self::assertSame($task, $dep->getSourceTask());
        self::assertSame($other, $dep->getTargetTask());
        self::assertSame(TaskDependencyType::BlockedBy, $dep->getDependencyType());
        self::assertNotEmpty($dep->getId());

        $doc = new TaskDocument($task, 'Notes', 'Body', $user, 2);
        self::assertNotEmpty($doc->getId());
        self::assertSame($task, $doc->getTask());
        self::assertSame('Notes', $doc->getTitle());
        self::assertSame('Body', $doc->getContent());
        self::assertSame(2, $doc->getPosition());

        $loggedAt = new DateTimeImmutable('2026-01-15');
        $entry    = new TaskTimeEntry($task, $user, 45, $loggedAt);
        self::assertNotEmpty($entry->getId());
        self::assertSame($loggedAt, $entry->getLoggedAt());
        self::assertNull($entry->getDescription());
    }

    public function testTaskChangeRecorderFormatsAdditionalValueTypes(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Work', new stdClass());
        $user  = new stdClass();
        $rec   = new TaskChangeRecorder();

        $rec->recordIfChanged($task, $user, TaskChangeType::Completed, false, true);
        $rec->recordIfChanged($task, $user, TaskChangeType::DueDate, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'));
        $rec->recordIfChanged($task, $user, TaskChangeType::Tags, ['b'], ['a', 'b']);
        $rec->record($task, $user, TaskChangeType::Title, 'same', 'same');

        self::assertCount(3, $task->getChangeHistory());
    }

    public function testTaskGanttBuilderExtendsPastIncompleteBarsToToday(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $board->addColumn(new BoardColumn($board, 'Todo', 0));

        $task = new Task(
            board: $board,
            title: 'Stale',
            creator: new stdClass(),
            dueAt: new DateTimeImmutable('-3 days'),
        );

        $ref  = new ReflectionClass($task);
        $prop = $ref->getProperty('createdAt');
        $prop->setValue($task, new DateTimeImmutable('-10 days'));

        $timeline = (new TaskGanttBuilder())->build([$task]);

        self::assertGreaterThanOrEqual($timeline->items[0]->startIndex, $timeline->items[0]->endIndex);
    }

    public function testTaskChangeHistoryExposesIdAndTask(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);
        $entry = new TaskChangeHistory($task, $user, TaskChangeType::Created, null, 'Work');

        self::assertNotEmpty($entry->getId());
        self::assertSame($task, $entry->getTask());
    }

    public function testBoardColumnManagerAcceptsNullColor(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $repo  = $this->createMock(\Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $column = (new BoardColumnManager($repo))->add($board, new BoardColumnFormData(name: 'Ideas'));

        self::assertNull($column->getColor());
    }

    public function testTaskMemberAssignerSkipsNonMatchingMembers(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $task   = new Task($board, 'Work', new stdClass());
        $first  = new TaskMember($task, new TestUser('1', 'a@example.com'), TaskMemberRole::Watcher);
        $second = new TaskMember($task, new TestUser('2', 'b@example.com'), TaskMemberRole::Assignee);
        $task->addMember($first)->addMember($second);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        self::assertTrue((new TaskMemberAssigner($repo, new TaskChangeRecorder()))->unassign($task, $second->getId(), new stdClass()));
        self::assertCount(1, $task->getMembers());
    }

    public function testTaskManagerHandlesOrphanColumnAndInvalidTargets(): void
    {
        $user       = new stdClass();
        $board      = new TaskBoard('Demo', 'demo', $user);
        $otherBoard = new TaskBoard('Other', 'other', $user);
        $orphanCol  = new BoardColumn($otherBoard, 'Foreign', 0);
        $localCol   = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($localCol);

        $noColumnTask = new Task($board, 'Floating', $user);
        $orphanTask   = new Task($board, 'Orphan', $user, column: $orphanCol);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findByBoard')->willReturn([$noColumnTask, $orphanTask]);
        $repo->expects(self::atLeastOnce())->method('save');

        $manager = new TaskManager($repo, new TaskChangeRecorder());

        self::assertNull($manager->getColumnNavigation($noColumnTask)->currentColumnName);
        self::assertNull($manager->getColumnNavigation($orphanTask)->currentColumnName);

        $manager->update($orphanTask, new TaskFormData(title: 'Orphan', columnId: 'missing-column'), $user);
        self::assertNull($orphanTask->getColumn());

        $bareBoard = new TaskBoard('Bare', 'bare', $user);
        $bareTask  = new Task($bareBoard, 'Lonely', $user);
        $manager->move($bareTask, 'missing-column', 0, $user);

        $emptyBoard = new TaskBoard('Empty', 'empty', $user);
        $created    = $manager->create($emptyBoard, new TaskFormData(title: 'First'), $user);
        self::assertSame('First', $created->getTitle());
        self::assertNull($created->getColumn());
    }

    public function testTaskGanttBuilderHandlesCompletedTasksAndBlockedByLinks(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $board->addColumn(new BoardColumn($board, 'Todo', 0));

        $completed = new Task(
            board: $board,
            title: 'Done task',
            creator: new stdClass(),
            dueAt: new DateTimeImmutable('+10 days'),
        );
        $completed->markCompleted();

        $blocked = new Task($board, 'Blocked', new stdClass());
        $blocked->getOutgoingDependencies()->add(new TaskDependency($blocked, $completed, TaskDependencyType::BlockedBy));

        $timeline = (new TaskGanttBuilder())->build([$completed, $blocked]);

        self::assertCount(2, $timeline->items);
        self::assertTrue($timeline->items[0]->completed);
        self::assertCount(1, $timeline->links);
    }

    public function testTaskLinkAttacherReturnsFalseForMissingLink(): void
    {
        $task     = new Task(new TaskBoard('Demo', 'demo', new stdClass()), 'Work', new stdClass());
        $attacher = new TaskLinkAttacher(
            $this->createMock(TaskRepositoryInterface::class),
            new GitLabMergeRequestLinkParser(),
            new TaskChangeRecorder(),
        );

        self::assertFalse($attacher->update($task, 'missing', new TaskLinkFormData(url: 'https://a.test'), new stdClass()));
        self::assertFalse($attacher->remove($task, 'missing', new stdClass()));
    }

    public function testTaskMemberAssignerUsesUsernameWhenEmailMissing(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Work', new stdClass());
        $user  = new class {
            public string $username = 'jdoe';
        };
        $actor = new stdClass();

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        (new TaskMemberAssigner($repo, new TaskChangeRecorder()))->assign(
            $task,
            new \Nowo\TaskBoardBundle\Dto\TaskMemberFormData(user: $user, memberRole: TaskMemberRole::Watcher),
            $actor,
        );

        self::assertSame('jdoe', $task->getChangeHistory()->first()->getNewValue());
    }

    public function testTaskMemberAssignerFallsBackToUserIdLabel(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Work', new stdClass());
        $user  = new TestUser('99', 'id-only@example.com');
        $repo  = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        (new TaskMemberAssigner($repo, new TaskChangeRecorder()))->assign(
            $task,
            new \Nowo\TaskBoardBundle\Dto\TaskMemberFormData(user: $user),
            new stdClass(),
        );

        self::assertSame('#99', $task->getChangeHistory()->first()->getNewValue());
    }

    public function testTaskMemberAssignerUsesUnknownLabelForPlainObject(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $task   = new Task($board, 'Work', new stdClass());
        $member = new TaskMember($task, new stdClass(), TaskMemberRole::Watcher);
        $task->addMember($member);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        (new TaskMemberAssigner($repo, new TaskChangeRecorder()))->unassign($task, $member->getId(), new stdClass());

        self::assertSame('unknown', $task->getChangeHistory()->first()->getOldValue());
    }

    public function testTaskManagerNavigationGuards(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $todo  = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($todo);
        $task = new Task($board, 'Work', new stdClass(), column: $todo);
        $user = new stdClass();
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findByBoard')->willReturn([$task]);

        $manager = new TaskManager($repo, new TaskChangeRecorder());

        self::assertFalse($manager->moveToPreviousColumn($task, $user));
        self::assertFalse($manager->moveToNextColumn($task, $user));
        self::assertFalse($manager->moveToColumn($task, '', $user));

        $emptyBoard = new TaskBoard('Empty', 'empty', new stdClass());
        $emptyTask  = new Task($emptyBoard, 'Lonely', new stdClass());
        self::assertFalse($manager->moveToDone($emptyTask, $user));
    }
}
