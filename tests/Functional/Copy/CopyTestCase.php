<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Copy;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingQuotaListener;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;

/**
 * Since 2.3.0 (specs 215, 217): a task that holds one of everything, and the services under test.
 */
abstract class CopyTestCase extends FunctionalTestCase
{
    /** @var list<array{0: string, 1: object}> event name, event */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
        $dispatcher   = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['task_created', 'task_moved', 'task_member_added', 'task_board_changed', 'task_completed', 'task_updated'] as $name) {
            $dispatcher->addListener('nowo_task_board.' . $name, function (object $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    protected function copier(): TaskCopier
    {
        $service = static::getContainer()->get(TaskCopier::class);
        self::assertInstanceOf(TaskCopier::class, $service);

        return $service;
    }

    protected function planner(): TaskMovePlanner
    {
        $service = static::getContainer()->get(TaskMovePlanner::class);
        self::assertInstanceOf(TaskMovePlanner::class, $service);

        return $service;
    }

    protected function mover(): TaskMover
    {
        $service = static::getContainer()->get(TaskMover::class);
        self::assertInstanceOf(TaskMover::class, $service);

        return $service;
    }

    protected function quota(): RecordingQuotaListener
    {
        $listener = static::getContainer()->get('app.quota_listener');
        self::assertInstanceOf(RecordingQuotaListener::class, $listener);

        return $listener;
    }

    /** Clears the entity manager and loads the owner again, so the next write finds a managed user. */
    protected function clear(): void
    {
        $this->em->clear();
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->owner = $owner;
    }

    protected function reload(Task $task): Task
    {
        $fresh = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);

        return $fresh;
    }

    protected function assertRefused(string $reason, callable $call): TaskCopyRefusedException
    {
        try {
            $call();
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame($reason, $exception->reason);

            return $exception;
        }
        self::fail('Expected the refusal ' . $reason);
    }

    /** @return list<string> */
    protected function eventNames(): array
    {
        return array_map(static fn (array $event): string => $event[0], $this->events);
    }

    /**
     * A task with fields, documents, a member, a comment, time (manual and aggregated), a dependency, links, history, an
     * open subtask with its own subtask, and an archived subtask.
     *
     * @return array{0: Task, 1: Task, 2: Task, 3: Task, 4: Task} source, subtask, grandchild, archived subtask, other task
     */
    protected function richTask(TaskBoard $board): array
    {
        $source = $this->task($board, 'Monthly invoices', 1);
        $source->setDescription('Send them all')->setPriority(TaskPriority::High)->setTags(['billing', 'monthly'])->setColor('#AA0000')->setEstimatedMinutes(90)
            ->setStartAt(new DateTimeImmutable('2026-11-01'))->setDueAt(new DateTimeImmutable('2026-11-03'));
        $source->addTimeSeconds(3600);
        $source->getDocuments()->add(new TaskDocument($source, 'Checklist', 'Step 1', $this->owner));
        $source->getDocuments()->add((new TaskDocument($source, 'Fields', 'Points: 3', $this->owner, 1))->markSyncFields());
        $source->addMember(new TaskMember($source, $this->owner, TaskMemberRole::Assignee));
        $source->addLink(new TaskLink($source, TaskLinkType::Url, 'https://example.com/a', 'A'));
        $source->addLink(new TaskLink($source, TaskLinkType::Url, 'https://example.com/b', 'B', 'ext-1', importSource: 'clickup'));
        $source->addTimeEntry(new TaskTimeEntry($source, $this->owner, 30, new DateTimeImmutable('2026-11-02')));
        $other = $this->task($board, 'Other');
        $source->addOutgoingDependency(new TaskDependency($source, $other, TaskDependencyType::Blocks));
        $this->em->persist(new TaskComment($source, $this->owner, 'Done last month'));
        $this->em->flush();

        $child = $this->task($board, 'Collect receipts', 1, $source);
        $child->setDueAt(new DateTimeImmutable('2026-11-02'))->markCompleted();
        $grandchild = $this->task($board, 'Scan', 0, $child);
        $archived   = $this->task($board, 'Old step', 0, $source, 1);
        $archived->archive();
        $this->em->flush();

        return [$source, $child, $grandchild, $archived, $other];
    }
}
