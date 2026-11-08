<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Event\TaskBoardChangedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Move\TaskMovePlan;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Spec 217, FR-001 and FR-004 – FR-010, SC-001, SC-002, SC-006: the plan says exactly what the move does; tracked time is
 * never deleted; a refusal or a failure changes nothing.
 */
final class TaskMoverTest extends CopyTestCase
{
    public function testThePlanSaysWhatTheMoveDoesAndTimeIsNeverDeleted(): void
    {
        self::$taskBoardConfig = ['assignee_provider' => 'app.assignee_provider'];
        self::ensureKernelShutdown();
        $this->setUp();
        $source                                         = $this->board('Source', ['To do', 'Doing', 'Done']);
        $target                                         = $this->board('Target', ['Backlog', 'doing', 'Done'], $this->user('ana@example.com'));
        [$task, $child, $grandchild, $archived, $other] = $this->richTask($source);
        $this->clear();
        $task   = $this->reload($task);
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        $plan = $this->planner()->plan($task, $target);

        self::assertSame([$task->getId()], [$plan->taskIds[0]]);
        self::assertCount(4, $plan->taskIds, 'the whole tree, the archived subtask too');
        self::assertSame(3, $plan->subtasks());
        self::assertFalse($plan->becomesTopLevel);
        self::assertSame(['owner@example.com'], $plan->membersRemoved);
        self::assertSame(['Other'], $plan->dependenciesRemoved);
        self::assertSame(2, $plan->openTasks, 'the task and the open grandchild; the done and the archived ones add nothing');
        self::assertSame(1, $plan->comments);
        self::assertSame(2, $plan->documents);
        self::assertSame(2, $plan->links);
        self::assertSame(1, $plan->reimportKeys);
        self::assertSame(1, $plan->timeEntries);
        self::assertSame(1, $plan->timePeople);
        self::assertSame(3600 + 1800, $plan->trackedSeconds);
        self::assertSame(0, $plan->syncDetached);
        self::assertFalse($plan->numberChanges);
        $rows = array_map(static fn (array $row): string => $row['source'] . '>' . $row['target'] . ':' . $row['tasks'], $plan->columns);
        self::assertContains('Doing>doing:1', $rows);
        self::assertContains('Doing>Done:1', $rows, 'a completed task goes to the last column');
        self::assertContains('To do>Backlog:2', $rows);

        $entriesBefore = $this->em->getRepository(TaskTimeEntry::class)->count([]);
        $moved         = $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
        self::assertSame($plan->fingerprint, $moved->fingerprint);
        $this->clear();

        foreach ([$task, $child, $grandchild, $archived] as $node) {
            $fresh = $this->reload($node);
            self::assertSame($target->getId(), $fresh->getBoard()->getId(), $fresh->getTitle());
            self::assertContains(TaskChangeType::BoardMoved, array_map(static fn (TaskChangeHistory $entry): TaskChangeType => $entry->getChangeType(), $fresh->getChangeHistory()->toArray()));
        }
        $task  = $this->reload($task);
        $child = $this->reload($child);
        self::assertSame('doing', $task->getColumn()?->getName());
        self::assertSame('Done', $child->getColumn()?->getName());
        self::assertTrue($child->isCompleted(), 'a completed task stays completed');
        self::assertTrue($this->reload($archived)->isArchived());
        self::assertCount(0, $task->getMembers(), 'members not assignable on the target are removed');
        self::assertCount(0, $task->getOutgoingDependencies());
        self::assertCount(0, $this->em->getRepository(TaskDependency::class)->findAll());
        self::assertContains(TaskChangeType::DependencyRemoved, array_map(static fn (TaskChangeHistory $entry): TaskChangeType => $entry->getChangeType(), $this->reload($other)->getChangeHistory()->toArray()), 'the task that stays says so too');
        self::assertSame(1, $this->em->getRepository(TaskComment::class)->count(['task' => $task->getId()]));
        self::assertCount(2, $task->getLinks());

        // SC-001: time — same count and total, the board snapshot follows.
        self::assertSame($entriesBefore, $this->em->getRepository(TaskTimeEntry::class)->count([]));
        self::assertSame(3600, $task->getTotalTimeSeconds());
        $entry = $task->getTimeEntries()->first();
        self::assertInstanceOf(TaskTimeEntry::class, $entry);
        self::assertSame(30, $entry->getMinutes());
        self::assertSame($target->getId(), $entry->getBoardIdSnapshot());

        // One TASK_BOARD_CHANGED after the commit; no TASK_MOVED and no TASK_COMPLETED.
        self::assertSame(['task_board_changed'], array_values(array_filter($this->eventNames(), static fn (string $name): bool => $name !== 'task_created')));
        $event = $this->events[0][1];
        self::assertInstanceOf(TaskBoardChangedEvent::class, $event);
        self::assertSame($source->getId(), $event->sourceBoardId);
        self::assertSame($target->getId(), $event->targetBoardId);
        self::assertSame($plan->taskIds, $event->taskIds);
    }

    public function testASubtaskMovesAloneBecomesTopLevelAndKeepsTheDependenciesInsideItsTree(): void
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $parent = $this->task($source, 'Parent');
        $child  = $this->task($source, 'Child', 0, $parent);
        $leaf   = $this->task($source, 'Leaf', 0, $child);
        $child->addOutgoingDependency(new TaskDependency($child, $leaf, TaskDependencyType::Blocks));
        $this->em->flush();
        $this->clear();
        $child  = $this->reload($child);
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        $plan = $this->planner()->plan($child, $target);
        self::assertTrue($plan->becomesTopLevel);
        self::assertSame([], $plan->dependenciesRemoved);
        $this->mover()->move($child, $target, [], $plan->fingerprint, $this->owner);
        $this->clear();

        $child = $this->reload($child);
        self::assertNull($child->getParent());
        self::assertSame($target->getId(), $this->reload($leaf)->getBoard()->getId());
        self::assertCount(1, $child->getOutgoingDependencies());
        self::assertSame($source->getId(), $this->reload($parent)->getBoard()->getId());
        self::assertCount(0, $this->reload($parent)->getSubtasks());
    }

    public function testTheChosenColumnWinsForOpenTasks(): void
    {
        $source = $this->board('Source', ['To do', 'Done']);
        $target = $this->board('Target', ['A', 'B', 'Done']);
        $task   = $this->task($source, 'Task');
        $this->task($target, 'Already there', 1);

        $map  = [(string) $source->getColumns()->get(0)?->getId() => (string) $target->getColumns()->get(1)?->getId()];
        $plan = $this->planner()->plan($task, $target, $map);
        self::assertSame($map, $plan->columnMap);
        $this->mover()->move($task, $target, $map, $plan->fingerprint, $this->owner);

        self::assertSame('B', $task->getColumn()?->getName());
        self::assertSame(1, $task->getPosition(), 'after the tasks already in the column');
    }

    public function testRefusalsWriteNothing(): void
    {
        $source   = $this->board('Source');
        $target   = $this->board('Target');
        $archived = $this->board('Archived');
        $archived->archive();
        $task = $this->task($source, 'Task');
        $this->task($source, 'Done one', 2, $task)->markCompleted();
        $this->em->flush();
        $this->clear();
        $task   = $this->reload($task);
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);

        $this->assertRefused('same_board', fn (): TaskMovePlan => $this->planner()->plan($task, $task->getBoard()));
        $this->assertRefused('board_archived', fn (): TaskMovePlan => $this->planner()->plan($task, $this->em->find(TaskBoard::class, $archived->getId()) ?? $target));
        $this->assertRefused('stale', fn (): TaskMovePlan => $this->mover()->move($task, $target, [], 'not-the-plan', $this->owner));

        $plan                = $this->planner()->plan($task, $target);
        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_MOVE];
        $refusal             = $this->assertRefused('quota', fn (): TaskMovePlan => $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner));
        self::assertSame('Your plan is full.', $refusal->hostMessage());
        self::assertSame(1, $this->quota()->asked[0]->count, 'the open tasks that arrive');
        self::assertSame($target, $this->quota()->asked[0]->board);

        $this->clear();
        self::assertSame($source->getId(), $this->reload($task)->getBoard()->getId());
        self::assertSame([], array_values(array_filter($this->eventNames(), static fn (string $name): bool => $name === 'task_board_changed')));
    }

    public function testAMoveOfDoneTasksOnlyDoesNotAskTheQuota(): void
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $task   = $this->task($source, 'Done', 2);
        $task->markCompleted();
        $this->em->flush();

        $plan = $this->planner()->plan($task, $target);
        $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);

        self::assertSame([], $this->quota()->asked);
        self::assertSame($target->getId(), $task->getBoard()->getId());
    }

    public function testASubtreeOverTheCapIsRefused(): void
    {
        self::$taskBoardConfig = ['move' => ['max_subtasks' => 0]];
        self::ensureKernelShutdown();
        $this->setUp();
        $source = $this->board('Source');
        $target = $this->board('Target');
        $task   = $this->task($source, 'Task');
        $this->task($source, 'Sub', 0, $task);
        $this->clear();

        $refusal = $this->assertRefused('subtree_too_large', fn (): TaskMovePlan => $this->planner()->plan($this->reload($task), $this->em->find(TaskBoard::class, $target->getId()) ?? $source));
        self::assertSame(['%count%' => 1, '%max%' => 0], $refusal->params);
    }

    public function testAFailureInsideTheMoveChangesNothing(): void
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $other  = $this->task($source, 'Stays');
        $task   = $this->task($source, 'Moves');
        $task->addOutgoingDependency(new TaskDependency($task, $other, TaskDependencyType::Blocks));
        $task->addTimeEntry(new TaskTimeEntry($task, $this->owner, 15, new DateTimeImmutable('2026-11-01')));
        $this->em->flush();
        $plan = $this->planner()->plan($task, $target);

        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(): never
            {
                throw new RuntimeException('database down');
            }
        });
        try {
            $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
            self::fail('The failure must reach the caller.');
        } catch (RuntimeException $exception) {
            self::assertSame('database down', $exception->getMessage());
        }

        $connection = $this->em->getConnection();
        self::assertSame($source->getId(), $connection->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_task_dependencies'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_task_time_entries'));
        self::assertSame([], array_values(array_filter($this->eventNames(), static fn (string $name): bool => $name === 'task_board_changed')));
    }
}
