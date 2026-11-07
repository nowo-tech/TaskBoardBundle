<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 2.1.0 (spec 214): the functional kernel with `calendar.enabled`.
 */
abstract class CalendarTestCase extends FunctionalTestCase
{
    protected const CONFIG = ['calendar' => ['enabled' => true], 'task_numbers' => ['enabled' => true]];

    protected function setUp(): void
    {
        static::$taskBoardConfig = static::$taskBoardConfig !== [] ? static::$taskBoardConfig : static::CONFIG;
        parent::setUp();
    }

    protected function dated(TaskBoard $board, string $title, ?string $start, ?string $due, ?Task $parent = null, int $column = 0): Task
    {
        $task = new Task(board: $board, title: $title, creator: $this->owner, column: $board->getColumns()->get($column), parent: $parent, dueAt: $due !== null ? new DateTimeImmutable($due) : null);
        $task->setStartAt($start !== null ? new DateTimeImmutable($start) : null);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    protected function calendarUrl(TaskBoard $board, string $query = ''): string
    {
        return '/tools/task-board/board/' . $board->getId() . '/calendar' . ($query !== '' ? '?' . $query : '');
    }
}
