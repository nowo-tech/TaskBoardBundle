<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;

/**
 * Since 2.17.0 (Beacon spec 234, FR-032 / FR-033 / FR-034): column types, dependencies across the boards of a folder and
 * shared status sets.
 */
final class ColumnTypesAndStatusSetsTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testAnUntypedBoardKeepsItsMeaningByPosition(): void
    {
        $board   = $this->board('Legacy', ['To do', 'Doing', 'Done']);
        $columns = ColumnTypes::ordered($board);
        self::assertSame(['open', 'custom', 'closed'], array_map(static fn (BoardColumn $c): string => ColumnTypes::of($c, $columns)->value, $columns));
        self::assertSame('Done', ColumnTypes::doneColumn($board)?->getName());
        $single = $this->board('One', ['Only']);
        self::assertTrue(ColumnTypes::isDone(ColumnTypes::ordered($single)[0]), 'a single untyped column completes, as before 2.17.0');
    }

    public function testTwoClosedColumnsCompleteTheirTasksAndAThirdIsRefused(): void
    {
        $board   = $this->board('AI Flow', ['Pendiente', 'En ejecución', 'Hecho', 'Cancelada', 'Archivo']);
        $columns = ColumnTypes::ordered($board);
        $manager = $this->columnManager();
        $manager->update($columns[2], new BoardColumnFormData('Hecho', null, null, ColumnType::Closed), $this->owner);
        self::assertSame(['open', 'custom', 'closed', 'custom', 'closed'], array_map(static fn (BoardColumn $c): string => ColumnTypes::of($c)->value, ColumnTypes::ordered($board)), 'the others keep their position types');
        $manager->update($columns[4], new BoardColumnFormData('Archivo', null, null, ColumnType::Custom), $this->owner);
        $manager->update($columns[3], new BoardColumnFormData('Cancelada', null, null, ColumnType::Closed), $this->owner);

        try {
            $manager->update($columns[1], new BoardColumnFormData('En ejecución', null, null, ColumnType::Closed), $this->owner);
            self::fail('a third closed column');
        } catch (InvalidArgumentException) {
        }

        $task = $this->task($board, 'Abandoned');
        $this->taskManager()->moveToColumn($task, $columns[3]->getId(), $this->owner);
        self::assertTrue($task->isCompleted(), 'the second closed column completes the task');
        $this->taskManager()->moveToColumn($task, $columns[4]->getId(), $this->owner);
        self::assertFalse($task->isCompleted(), 'the last column, now custom, reopens it');
        $this->taskManager()->moveToDone($task, $this->owner);
        self::assertSame('Hecho', $task->getColumn()?->getName(), 'done goes to the first closed column');
    }

    public function testRetypingAColumnCompletesItsTasks(): void
    {
        $board   = $this->board('Board', ['A', 'B', 'C']);
        $columns = ColumnTypes::ordered($board);
        $task    = $this->task($board, 'In B', 1);
        $this->columnManager()->update($columns[1], new BoardColumnFormData('B', null, null, ColumnType::Done), $this->owner);
        $this->clear();
        self::assertTrue($this->reload($task)->isCompleted());
    }

    public function testDependenciesJoinBoardsOfOneFolderOnly(): void
    {
        $service = $this->restructurer();
        $folder  = $service->createFolder($this->owner, 'acme', 'Epic');
        $one     = $this->board('Story 1', tenantKey: 'acme');
        $two     = $this->board('Story 2', tenantKey: 'acme');
        $outside = $this->board('Elsewhere', tenantKey: 'acme');
        $service->moveBoardToFolder($this->owner, $one->getId(), $folder->id);
        $service->moveBoardToFolder($this->owner, $two->getId(), $folder->id);
        $a = $this->task($one, 'A');
        $b = $this->task($two, 'B');
        $c = $this->task($outside, 'C');

        $this->dependencies()->add($a, $b, TaskDependencyType::Blocks, $this->owner);
        self::assertSame(1, $this->em->getRepository(TaskDependency::class)->count([]));
        try {
            $this->dependencies()->add($b, $a, TaskDependencyType::Blocks, $this->owner);
            self::fail('a cycle across the folder');
        } catch (InvalidTaskDependencyException $refused) {
            self::assertSame(InvalidTaskDependencyException::REASON_CYCLE, $refused->reason);
        }
        try {
            $this->dependencies()->add($a, $c, TaskDependencyType::Blocks, $this->owner);
            self::fail('LINK_002 for a board outside the folder');
        } catch (InvalidTaskDependencyException $refused) {
            self::assertSame(InvalidTaskDependencyException::REASON_OTHER_BOARD, $refused->reason);
        }
    }

    public function testASharedStatusSetReachesEveryBoardAndNeverOrphansATask(): void
    {
        $service = $this->restructurer();
        $folder  = $service->createFolder($this->owner, 'acme', 'Epic');
        $one     = $this->board('Story 1', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $two     = $this->board('Story 2', ['Backlog', 'Done'], tenantKey: 'acme');
        $service->moveBoardToFolder($this->owner, $one->getId(), $folder->id);
        $service->moveBoardToFolder($this->owner, $two->getId(), $folder->id);
        $kept       = $this->task($two, 'In backlog', 0);
        $doneColumn = ColumnTypes::ordered($two)[1]->getId();
        $set        = [new BoardColumnSpec('Todo', ColumnType::Open), new BoardColumnSpec('Doing'), new BoardColumnSpec('Hecho', ColumnType::Closed), new BoardColumnSpec('Cancelada', ColumnType::Closed)];

        try {
            $this->statusSets()->setStatusSet($this->owner, $folder->id, $set);
            self::fail('Backlog holds a task and the set drops it');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::COLUMNS_IN_USE, $refused->reason);
            self::assertSame('Backlog', $refused->params['columns']);
        }
        $this->clear();
        self::assertNull($this->statusSets()->statusSet($folder->id), 'nothing written');

        $this->statusSets()->setStatusSet($this->owner, $folder->id, $set, [$two->getId() => ['Backlog' => 'Todo', 'Done' => 'Hecho']]);
        $this->clear();
        foreach ([$one, $two] as $board) {
            $fresh = $this->em->find(TaskBoard::class, $board->getId());
            self::assertInstanceOf(TaskBoard::class, $fresh);
            self::assertSame(['Todo:open', 'Doing:custom', 'Hecho:closed', 'Cancelada:closed'], array_map(static fn (BoardColumn $c): string => $c->getName() . ':' . ColumnTypes::of($c)->value, ColumnTypes::ordered($fresh)));
        }
        self::assertSame('Todo', $this->reload($kept)->getColumn()?->getName());
        self::assertNotNull($this->em->find(BoardColumn::class, $doneColumn), 'Done was renamed Hecho, so it kept its id');

        // An edit on one board reaches the other.
        $fresh = $this->em->find(TaskBoard::class, $one->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $this->columnManager()->update(ColumnTypes::ordered($fresh)[1], new BoardColumnFormData('En ejecución'), $this->owner);
        $this->clear();
        $other = $this->em->find(TaskBoard::class, $two->getId());
        self::assertInstanceOf(TaskBoard::class, $other);
        self::assertSame('En ejecución', ColumnTypes::ordered($other)[1]->getName());

        // A board that joins the folder takes the set.
        $three = $this->board('Story 3', ['Todo', 'Old'], tenantKey: 'acme');
        $this->task($three, 'In old', 1);
        try {
            $service->moveBoardToFolder($this->owner, $three->getId(), $folder->id);
            self::fail('Old holds a task');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::COLUMNS_IN_USE, $refused->reason);
        }
        $service->moveBoardToFolder($this->owner, $three->getId(), $folder->id, ['Old' => 'Cancelada']);
        $this->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'In old']);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Cancelada', $task->getColumn()?->getName());
        self::assertTrue($task->isCompleted(), 'a closed column completes it');
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = static::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }

    private function statusSets(): FolderStatusSets
    {
        $service = static::getContainer()->get(FolderStatusSets::class);
        self::assertInstanceOf(FolderStatusSets::class, $service);

        return $service;
    }

    private function columnManager(): BoardColumnManager
    {
        $service = static::getContainer()->get(BoardColumnManager::class);
        self::assertInstanceOf(BoardColumnManager::class, $service);

        return $service;
    }

    private function taskManager(): TaskManager
    {
        $service = static::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $service);

        return $service;
    }

    private function dependencies(): TaskDependencyManager
    {
        $service = static::getContainer()->get(TaskDependencyManager::class);
        self::assertInstanceOf(TaskDependencyManager::class, $service);

        return $service;
    }
}
