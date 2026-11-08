<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Copy;

use Nowo\TaskBoardBundle\Copy\TaskColumnRule;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * Specs 215 and 217: the column a copied or moved task lands in.
 */
final class TaskColumnRuleTest extends TestCase
{
    public function testTheRuleInOrder(): void
    {
        $source                            = $this->board(['To do', 'In QA', 'Done']);
        $target                            = $this->board(['Backlog', 'in qa', 'Done']);
        [$todo, $qa, $done]                = $source->getColumns()->toArray();
        [$backlog, $targetQa, $targetDone] = $target->getColumns()->toArray();

        self::assertSame($targetDone, TaskColumnRule::target($target, $qa, true), 'completed: the last column');
        self::assertSame($backlog, TaskColumnRule::target($target, $qa, false, $backlog->getId()), 'the chosen column');
        self::assertSame($targetQa, TaskColumnRule::target($target, $qa, false, $targetDone->getId()), 'a chosen last column is ignored');
        self::assertSame($qa, TaskColumnRule::target($source, $qa, false), 'its own column on the same board');
        self::assertSame($todo, TaskColumnRule::target($source, $done, false), 'never the last column for an open task');
        self::assertSame($targetQa, TaskColumnRule::target($target, $qa, false), 'the same name after normalisation');
        self::assertSame($backlog, TaskColumnRule::target($target, $todo, false), 'else the first');
        self::assertSame($backlog, TaskColumnRule::target($target, null, false));
        self::assertNull(TaskColumnRule::target($this->board([]), $qa, false));

        $single = $this->board(['Only']);
        self::assertSame([$single->getColumns()->first()], TaskColumnRule::openColumns($single));
        self::assertSame($single->getColumns()->first(), TaskColumnRule::target($single, null, false, $single->getColumns()->first()->getId()));
        self::assertCount(2, TaskColumnRule::openColumns($target));
    }

    /**
     * @param list<string> $names
     */
    private function board(array $names): TaskBoard
    {
        $board = new TaskBoard('Board', 'board', new TestUser('u1', 'u1'));
        foreach ($names as $position => $name) {
            $board->addColumn(new BoardColumn($board, $name, $position));
        }

        return $board;
    }
}
