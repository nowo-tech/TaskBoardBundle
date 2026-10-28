<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCompletedEvent;
use Nowo\TaskBoardBundle\Event\TaskCreatedEvent;
use Nowo\TaskBoardBundle\Event\TaskMemberAddedEvent;
use Nowo\TaskBoardBundle\Event\TaskMovedEvent;
use Nowo\TaskBoardBundle\Event\TaskReopenedEvent;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

final class TaskEventsTest extends TestCase
{
    private TaskBoard $board;

    private BoardColumn $todo;

    private BoardColumn $doing;

    private BoardColumn $done;

    private object $user;

    /** @var list<array{string, Event}> */
    private array $events = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->user  = new stdClass();
        $this->board = new TaskBoard('Board', 'board', $this->user);
        $this->todo  = new BoardColumn($this->board, 'To do', 0);
        $this->doing = new BoardColumn($this->board, 'Doing', 1);
        $this->done  = new BoardColumn($this->board, 'Done', 2);
        $this->board->addColumn($this->todo)->addColumn($this->doing)->addColumn($this->done);

        $this->dispatcher = new EventDispatcher();
        foreach ([TaskBoardEvents::TASK_CREATED, TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_COMPLETED, TaskBoardEvents::TASK_REOPENED, TaskBoardEvents::TASK_MEMBER_ADDED] as $name) {
            $this->dispatcher->addListener($name, function (Event $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    public function testCreateDispatchesCreatedAfterSave(): void
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturn([]);
        $repository->expects(self::once())->method('save')->willReturnCallback(function (): void {
            self::assertSame([], $this->events, 'Events are dispatched after the write is saved');
        });

        $task = $this->manager($repository)->create($this->board, new TaskFormData(title: 'New'), $this->user);

        self::assertSame([TaskBoardEvents::TASK_CREATED], $this->names());
        $event = $this->events[0][1];
        self::assertInstanceOf(TaskCreatedEvent::class, $event);
        self::assertSame($task, $event->getTask());
        self::assertSame($this->user, $event->getActor());
    }

    public function testUpdateDispatchesChangedFieldsAndMove(): void
    {
        $task = new Task($this->board, 'Old', $this->user, column: $this->todo, dueAt: new DateTimeImmutable('2026-01-01'), tags: ['a']);

        $this->manager()->update($task, new TaskFormData(
            title: 'New',
            description: 'Text',
            priority: TaskPriority::High,
            columnId: $this->doing->getId(),
            estimatedMinutes: 30,
            dueAt: new DateTimeImmutable('2026-02-01'),
            tags: ['b'],
        ), $this->user);

        self::assertSame([TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_MOVED], $this->names());
        $updated = $this->events[0][1];
        self::assertInstanceOf(TaskUpdatedEvent::class, $updated);
        self::assertSame(['title', 'description', 'priority', 'column', 'estimatedMinutes', 'dueAt', 'tags'], $updated->getChangedFields());
        self::assertTrue($updated->hasChanged('title'));
        self::assertFalse($updated->hasChanged('other'));
        $moved = $this->events[1][1];
        self::assertInstanceOf(TaskMovedEvent::class, $moved);
        self::assertSame($this->todo, $moved->getFromColumn());
        self::assertSame($this->doing, $moved->getToColumn());
    }

    public function testUpdateWithoutChangesDispatchesNothing(): void
    {
        $task = new Task($this->board, 'Same', $this->user, column: $this->todo);

        $this->manager()->update($task, new TaskFormData(title: 'Same', columnId: $this->todo->getId()), $this->user);

        self::assertSame([], $this->names());
    }

    public function testUpdatePriorityDispatchesOnlyOnChange(): void
    {
        $task    = new Task($this->board, 'Task', $this->user, column: $this->todo);
        $manager = $this->manager();

        $manager->updatePriority($task, TaskPriority::Normal, $this->user);
        self::assertSame([], $this->names());

        $manager->updatePriority($task, TaskPriority::Urgent, $this->user);
        self::assertSame([TaskBoardEvents::TASK_UPDATED], $this->names());
        $event = $this->events[0][1];
        self::assertInstanceOf(TaskUpdatedEvent::class, $event);
        self::assertSame(['priority'], $event->getChangedFields());
    }

    public function testMovingToDoneCompletesAndBackReopens(): void
    {
        $task    = new Task($this->board, 'Task', $this->user, column: $this->doing);
        $manager = $this->manager();

        self::assertTrue($manager->moveToDone($task, $this->user));
        self::assertSame([TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_COMPLETED], $this->names());
        self::assertInstanceOf(TaskCompletedEvent::class, $this->events[1][1]);

        $this->events = [];
        self::assertTrue($manager->moveToPreviousColumn($task, $this->user));
        self::assertSame([TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_REOPENED], $this->names());
        self::assertInstanceOf(TaskReopenedEvent::class, $this->events[1][1]);

        // A reorder inside the same column is not a move.
        $this->events = [];
        $manager->move($task, $this->doing->getId(), 0, $this->user);
        self::assertSame([], $this->names());
    }

    public function testWithoutDispatcherNoEventIsNeeded(): void
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturn([]);
        $task = (new TaskManager($repository, new TaskChangeRecorder()))->create($this->board, new TaskFormData(title: 'New'), $this->user);

        self::assertSame('New', $task->getTitle());
    }

    public function testMemberAddedEventAndAssigneeProvider(): void
    {
        $alice    = new stdClass();
        $mallory  = new stdClass();
        $provider = $this->createMock(TaskAssigneeProviderInterface::class);
        $provider->method('assignableUsers')->willReturn([$alice]);
        $provider->method('canBeAssigned')->willReturnCallback(static fn (TaskBoard $board, object $user): bool => $user === $alice);
        $assigner = new TaskMemberAssigner($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder(), $provider, $this->dispatcher);
        $task     = new Task($this->board, 'Task', $this->user);

        self::assertSame([$alice], $assigner->assignableUsers($this->board));
        $member = $assigner->assign($task, new TaskMemberFormData($alice, TaskMemberRole::Assignee), $this->user);
        self::assertSame([TaskBoardEvents::TASK_MEMBER_ADDED], $this->names());
        $event = $this->events[0][1];
        self::assertInstanceOf(TaskMemberAddedEvent::class, $event);
        self::assertSame($member, $event->getMember());
        self::assertSame($alice, $event->getUser());

        // The same membership again: no new member, no event.
        $this->events = [];
        self::assertSame($member, $assigner->assign($task, new TaskMemberFormData($alice, TaskMemberRole::Assignee), $this->user));
        self::assertSame([], $this->names());

        $this->expectException(UnassignableUserException::class);
        $this->expectExceptionMessage($this->board->getId());
        $assigner->assign($task, new TaskMemberFormData($mallory), $this->user);
    }

    public function testWithoutAssigneeProviderEveryUserMayBeAdded(): void
    {
        $assigner = new TaskMemberAssigner($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder());

        self::assertNull($assigner->assignableUsers($this->board));
        self::assertCount(1, $assigner->assign(new Task($this->board, 'Task', $this->user), new TaskMemberFormData(new stdClass()), $this->user)->getTask()->getMembers());
    }

    public function testBoardCreatorUsesTheConfiguredColumnsAndTenant(): void
    {
        $repository = $this->createMock(TaskBoardRepositoryInterface::class);
        $repository->method('findBySlug')->willReturn(null);
        $creator = new TaskBoardCreator($repository, [['name' => 'Backlog', 'color' => null], ['name' => 'Shipped', 'color' => '#22c55e']]);

        $board = $creator->create(new TaskBoardFormData(name: 'Shop'), $this->user, 'project-42');

        self::assertSame('project-42', $board->getTenantKey());
        self::assertSame(['Backlog', 'Shipped'], array_map(static fn (BoardColumn $column): string => $column->getName(), $board->getColumns()->toArray()));
        self::assertNull((new TaskBoardCreator($repository))->create(new TaskBoardFormData(name: 'Plain'), $this->user)->getTenantKey());
    }

    private function manager(?TaskRepositoryInterface $repository = null): TaskManager
    {
        if (!$repository instanceof TaskRepositoryInterface) {
            $repository = $this->createMock(TaskRepositoryInterface::class);
            $repository->method('findByBoard')->willReturn([]);
        }

        return new TaskManager($repository, new TaskChangeRecorder(), $this->dispatcher);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], $this->events);
    }
}
