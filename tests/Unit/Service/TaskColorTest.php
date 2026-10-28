<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.14: the task colour (entity, create / update with its history and event, Gantt item).
 */
final class TaskColorTest extends TestCase
{
    private TaskBoard $board;

    private BoardColumn $todo;

    private object $user;

    protected function setUp(): void
    {
        $this->user  = new stdClass();
        $this->board = new TaskBoard('Board', 'board', $this->user);
        $this->todo  = new BoardColumn($this->board, 'To do', 0);
        $this->board->addColumn($this->todo);
    }

    public function testEntityStoresTheColourInLowerCaseAndEmptyAsNull(): void
    {
        $task = new Task($this->board, 'Task', $this->user);
        self::assertNull($task->getColor());

        self::assertSame($task, $task->setColor('#3B82F6'));
        self::assertSame('#3b82f6', $task->getColor());

        $task->setColor('');
        self::assertNull($task->getColor());

        $task->setColor('#10b981');
        $task->setColor(null);
        self::assertNull($task->getColor());
    }

    public function testTheStartDateIsCreatedUpdatedAndRecorded(): void
    {
        $task = $this->manager()->create($this->board, new TaskFormData(title: 'New', startAt: new DateTimeImmutable('2026-11-02')), $this->user);
        self::assertSame('2026-11-02', $task->getStartAt()?->format('Y-m-d'));

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $this->manager($dispatcher)->update($task, new TaskFormData(title: 'New', columnId: $this->todo->getId(), startAt: new DateTimeImmutable('2026-11-03')), $this->user);

        self::assertContains('startAt', $events[0]->getChangedFields());
        $types = array_map(static fn ($entry): TaskChangeType => $entry->getChangeType(), $task->getChangeHistory()->toArray());
        self::assertContains(TaskChangeType::StartDate, $types);
    }

    public function testCreateKeepsTheColour(): void
    {
        $task = $this->manager()->create($this->board, new TaskFormData(title: 'New', color: '#EF4444'), $this->user);

        self::assertSame('#ef4444', $task->getColor());
    }

    public function testUpdateRecordsTheColourAndReportsItAsChanged(): void
    {
        $task = new Task($this->board, 'Task', $this->user, column: $this->todo);
        /** @var list<object> $events */
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $this->manager($dispatcher)->update($task, new TaskFormData(title: 'Task', columnId: $this->todo->getId(), color: '#3B82F6'), $this->user);

        self::assertSame('#3b82f6', $task->getColor());
        self::assertCount(1, $events);
        self::assertInstanceOf(TaskUpdatedEvent::class, $events[0]);
        self::assertSame(['color'], $events[0]->getChangedFields());

        $history = $task->getChangeHistory()->last();
        self::assertNotFalse($history);
        self::assertSame(TaskChangeType::Color, $history->getChangeType());
        self::assertNull($history->getOldValue());
        self::assertSame('#3b82f6', $history->getNewValue());

        // Same colour in another case: no change, no history entry, no event.
        $this->manager($dispatcher)->update($task, new TaskFormData(title: 'Task', columnId: $this->todo->getId(), color: '#3b82F6'), $this->user);
        self::assertCount(1, $events);
        self::assertCount(1, $task->getChangeHistory());
    }

    public function testGanttItemCarriesTheColour(): void
    {
        $plain    = new Task($this->board, 'Plain', $this->user, dueAt: new DateTimeImmutable('+2 days'));
        $coloured = (new Task($this->board, 'Coloured', $this->user, dueAt: new DateTimeImmutable('+3 days')))->setColor('#8b5cf6');

        $timeline = (new TaskGanttBuilder())->build([$plain, $coloured]);

        $colours = [];
        foreach ($timeline->items as $item) {
            $colours[$item->title] = $item->color;
        }
        self::assertSame(['Plain' => null, 'Coloured' => '#8b5cf6'], $colours);
    }

    private function manager(?EventDispatcher $dispatcher = null): TaskManager
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturn([]);

        return new TaskManager($repository, new TaskChangeRecorder(), $dispatcher);
    }
}
