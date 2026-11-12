<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskTimeEntryRepositoryInterface;
use Nowo\TaskBoardBundle\Service\NullTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableDefaultProviderInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 1.15: manual time entries logged from the task detail.
 */
final class TaskTimeLoggerTest extends TestCase
{
    public function testLogsAndRemovesEntriesWithTheirHistory(): void
    {
        $user = new stdClass();
        $task = new Task(new TaskBoard('Board', 'board', $user), 'Task', $user);
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::exactly(4))->method('save')->with($task);
        $entries = $this->createMock(TaskTimeEntryRepositoryInterface::class);
        $logger  = new TaskTimeLogger($repo, new TaskChangeRecorder(), $entries);

        $first = $logger->log($task, $user, 90, new DateTimeImmutable('2026-10-27'), '  Pairing  ');
        // Since 1.23.2: removing an entry deletes it explicitly (no orphan removal on the task any more).
        $entries->expects(self::once())->method('remove')->with($first);
        $second = $logger->log($task, $user, 30, new DateTimeImmutable('2026-10-28'), '   ');

        self::assertSame('Pairing', $first->getDescription());
        self::assertNull($second->getDescription());
        self::assertSame(120, $task->getLoggedMinutes());
        $entry = $task->getChangeHistory()->last();
        self::assertNotFalse($entry);
        self::assertSame(TaskChangeType::TimeLogged, $entry->getChangeType());
        self::assertSame('30', $entry->getNewValue());
        self::assertSame('2026-10-28', $entry->getContext());

        self::assertFalse($logger->remove($task, 'missing', $user));
        self::assertTrue($logger->remove($task, $first->getId(), $user));
        self::assertSame(30, $task->getLoggedMinutes());
        $entry = $task->getChangeHistory()->last();
        self::assertNotFalse($entry);
        self::assertSame(TaskChangeType::TimeRemoved, $entry->getChangeType());
        self::assertSame('90', $entry->getOldValue());

        self::assertNull((new TaskTimeLogger($repo, new TaskChangeRecorder(), $entries))->log($task, $user, 5, new DateTimeImmutable('2026-10-28'))->getDescription());
    }

    public function testTheBillableFlagComesFromTheCallerOrTheHostDefault(): void
    {
        $user     = new stdClass();
        $task     = new Task(new TaskBoard('Board', 'board', $user), 'Task', $user);
        $repo     = $this->createMock(TaskRepositoryInterface::class);
        $entries  = $this->createMock(TaskTimeEntryRepositoryInterface::class);
        $defaults = $this->createMock(TaskTimeBillableDefaultProviderInterface::class);
        $defaults->expects(self::exactly(2))->method('defaultFor')->with($user, $task->getId(), $task->getBoard()->getId())->willReturnOnConsecutiveCalls(true, null);
        $logger = new TaskTimeLogger($repo, new TaskChangeRecorder(), $entries, null, $defaults);

        self::assertTrue($logger->log($task, $user, 10, new DateTimeImmutable('2026-11-10'))->getBillable(), 'the host default');
        self::assertFalse($logger->log($task, $user, 10, new DateTimeImmutable('2026-11-10'), null, false)->getBillable(), 'an explicit value does not ask');
        self::assertNull($logger->log($task, $user, 10, new DateTimeImmutable('2026-11-10'))->getBillable(), 'a null default keeps it unset');
        self::assertNull((new TaskTimeLogger($repo, new TaskChangeRecorder(), $entries))->log($task, $user, 10, new DateTimeImmutable('2026-11-10'))->getBillable(), 'no provider: unset, as before');
        self::assertNull((new NullTaskTimeBillableDefaultProvider())->defaultFor($user, 't', null));
    }

    public function testAddingTheSameEntryTwiceKeepsOne(): void
    {
        $user  = new stdClass();
        $task  = new Task(new TaskBoard('Board', 'board', $user), 'Task', $user);
        $entry = new TaskTimeEntry($task, $user, 10, new DateTimeImmutable());

        $task->addTimeEntry($entry)->addTimeEntry($entry);

        self::assertCount(1, $task->getTimeEntries());

        $other      = new Task($task->getBoard(), 'Other', $user);
        $dependency = new TaskDependency($task, $other, TaskDependencyType::Related);
        $task->addOutgoingDependency($dependency)->addOutgoingDependency($dependency);
        self::assertCount(1, $task->getOutgoingDependencies());
        self::assertCount(1, $other->getIncomingDependencies());
        $task->removeDependency($dependency);
        self::assertCount(0, $other->getIncomingDependencies());
    }
}
