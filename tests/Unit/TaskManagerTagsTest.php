<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskManagerTagsTest extends TestCase
{
    public function testCreatePersistsNormalizedTags(): void
    {
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $column = new BoardColumn($board, 'To do', 0);
        $board->addColumn($column);
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByBoard')
            ->willReturn([]);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn ($task): bool => $task->getTags() === ['api', 'backend']));

        $manager = new TaskManager($repository, new TaskChangeRecorder());
        $task    = $manager->create(
            $board,
            new TaskFormData(
                title: 'API task',
                priority: TaskPriority::Normal,
                columnId: $column->getId(),
                tags: [' api ', 'backend', 'backend', ''],
            ),
            $user,
        );

        self::assertSame(['api', 'backend'], $task->getTags());
        self::assertCount(1, $task->getChangeHistory());
    }
}
