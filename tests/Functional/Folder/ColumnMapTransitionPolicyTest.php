<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Configuration\BoardConfiguration;
use Nowo\TaskBoardBundle\Configuration\BoardConfigurationApplier;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureViaInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use ReflectionMethod;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Audit PW-D2-L1 (TaskBoard 2.20.0): a folder's status set, a board configuration and a board joining a sharing folder,
 * each with a column map, move tasks. They now ask the column transition policy for every task the map moves, through
 * the caller's channel (`$via`; none: `web`), before the transaction: the first refusal changes nothing.
 */
final class ColumnMapTransitionPolicyTest extends CopyTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['column_transition_policy' => HumansOnlyColumnTransitionPolicy::class];
        parent::setUp();
    }

    public function testAStatusSetMovingATaskIntoAHumansOnlyColumnIsRefusedForAMachineChannel(): void
    {
        [, $two, $folderId] = $this->shared();
        $task               = $this->task($this->board2($two), 'Sibling', 1);
        $archived           = $this->task($this->board2($two), 'Archived first', 1);
        $archived->archive();
        $this->em->flush();
        $this->clear();
        $sets = $this->service(FolderStatusSets::class);
        $set  = [new BoardColumnSpec('Todo', ColumnType::Open, humansOnly: true), new BoardColumnSpec('Done', ColumnType::Closed)];

        try {
            $sets->setStatusSet($this->owner, $folderId, $set, [$two->getId() => ['Doing' => 'Todo']], new ColumnTransitionVia('mcp', ColumnTransitionVia::OP_COLUMNS));
            self::fail('Refused expected.');
        } catch (ColumnTransitionRefusedException $refused) {
            self::assertSame('mcp', $refused->transition->via->channel);
        }
        $this->clear();
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($two));
        self::assertSame('Doing', $this->reload($task)->getColumn()?->getName());

        // Without a channel (host code, as in 2.19): `web`, which the humans-only policy lets through.
        $sets->setStatusSet($this->owner, $folderId, $set, [$two->getId() => ['Doing' => 'Todo']]);
        $this->clear();
        self::assertSame('Todo', $this->reload($task)->getColumn()?->getName());
    }

    public function testABoardConfigurationWithAColumnMapAsksThePolicy(): void
    {
        $board = $this->board('Solo', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $task  = $this->task($board, 'Moved', 1);
        $this->clear();
        $applier = $this->service(BoardConfigurationApplier::class);
        $config  = new BoardConfiguration([new BoardColumnSpec('Todo', ColumnType::Open, humansOnly: true), new BoardColumnSpec('Done', ColumnType::Closed)]);

        try {
            $applier->apply($this->owner, $this->board2($board), $config, ['Doing' => 'Todo'], via: new ColumnTransitionVia('api', ColumnTransitionVia::OP_COLUMNS));
            self::fail('Refused expected.');
        } catch (ColumnTransitionRefusedException) {
        }
        $this->clear();
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($board));
        self::assertSame('Doing', $this->reload($task)->getColumn()?->getName());
    }

    public function testJoiningASharingFolderWithAColumnMapAsksThePolicy(): void
    {
        [, , $folderId] = $this->shared();
        $newcomer       = $this->board('Newcomer', ['Backlog', 'Done'], tenantKey: 'acme');
        $task           = $this->task($newcomer, 'Waiting', 0);
        $this->clear();
        $restructure = $this->service(TaskBoardRestructureInterface::class);
        // The 2.19 port keeps its signature (host classes implement it); the channel comes through its companion port.
        self::assertInstanceOf(TaskBoardRestructureViaInterface::class, $restructure);
        self::assertSame($restructure, $this->service(TaskBoardRestructureViaInterface::class));
        self::assertSame(4, (new ReflectionMethod(TaskBoardRestructureInterface::class, 'moveBoardToFolder'))->getNumberOfParameters());

        try {
            $restructure->moveBoardToFolderVia($this->owner, $newcomer->getId(), $folderId, ['Backlog' => 'Todo'], new ColumnTransitionVia('mcp', ColumnTransitionVia::OP_COLUMNS));
            self::fail('Refused expected.');
        } catch (ColumnTransitionRefusedException) {
        }
        $this->clear();
        self::assertNull($this->board2($newcomer)->getFolderId());
        self::assertSame(['Backlog', 'Done'], $this->names($newcomer));
        self::assertSame('Backlog', $this->reload($task)->getColumn()?->getName());

        $restructure->moveBoardToFolder($this->owner, $newcomer->getId(), $folderId, ['Backlog' => 'Todo']);
        $this->clear();
        self::assertSame($folderId, $this->board2($newcomer)->getFolderId());
        self::assertSame('Todo', $this->reload($task)->getColumn()?->getName());
    }

    /**
     * Two boards of a folder sharing Todo / Doing / Done, Todo flagged humans only.
     *
     * @return array{0: TaskBoard, 1: TaskBoard, 2: string}
     */
    private function shared(): array
    {
        $one = $this->board('One', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $two = $this->board('Two', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $restructure = $this->service(TaskBoardRestructureInterface::class);
        $folder      = $restructure->createFolder($this->owner, 'acme', 'Shared');
        $restructure->moveBoardToFolder($this->owner, $one->getId(), $folder->id);
        $restructure->moveBoardToFolder($this->owner, $two->getId(), $folder->id);
        $this->service(FolderStatusSets::class)->shareFrom($this->owner, $folder->id, $one->getId());
        $this->service(BoardColumnManager::class)->setHumansOnly(ColumnTypes::ordered($this->board2($one))[0], true, $this->owner);

        return [$one, $two, $folder->id];
    }

    private function board2(TaskBoard $board): TaskBoard
    {
        $found = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function names(TaskBoard $board): array
    {
        return array_map(static fn (BoardColumn $column): string => $column->getName(), ColumnTypes::ordered($this->board2($board)));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
