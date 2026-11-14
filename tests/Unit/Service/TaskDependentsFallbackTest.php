<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Search\DoctrineTaskTextSearch;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\ValueObject\TaskTextQuery;
use PHPUnit\Framework\TestCase;

final class TaskDependentsFallbackTest extends TestCase
{
    public function testWithoutTheLookupTheLoadedRelationsAnswerWithinTheTenant(): void
    {
        $board = (new TaskBoard('Roadmap', 'roadmap', null))->setTenantKey('tenant-a');
        $other = (new TaskBoard('Other', 'other', null))->setTenantKey('tenant-b');
        $task  = new Task($board, 'Blocker', null);
        $waits = new Task($board, 'Waits', null);
        $after = new Task($board, 'After', null);
        $peer  = new Task($board, 'Related', null);
        $far   = new Task($other, 'Elsewhere', null);
        foreach ([
            new TaskDependency($waits, $task, TaskDependencyType::BlockedBy),
            new TaskDependency($far, $task, TaskDependencyType::BlockedBy),
            new TaskDependency($peer, $task, TaskDependencyType::Related),
        ] as $incoming) {
            $incoming->getSourceTask()->addOutgoingDependency($incoming);
        }
        $task->addOutgoingDependency(new TaskDependency($task, $after, TaskDependencyType::Blocks));
        $task->addOutgoingDependency(new TaskDependency($task, $peer, TaskDependencyType::BlockedBy));

        $manager = new TaskDependencyManager($this->createStub(TaskRepositoryInterface::class), new TaskChangeRecorder());
        self::assertSame([$waits, $after], $manager->dependentsOf($task));
    }

    public function testAnEmptyTextMatchesNothingWithoutAQuery(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getConnection');
        $search = new DoctrineTaskTextSearch($manager);
        self::assertSame([], $search->matchingTaskIds(new TaskTextQuery('   ', ['b1'], 10)));
        self::assertSame([], $search->matchingTaskIds(new TaskTextQuery('x', [], 10)));
        self::assertSame([], $search->matchingTaskIds(new TaskTextQuery('x', ['b1'], 0)));
    }
}
