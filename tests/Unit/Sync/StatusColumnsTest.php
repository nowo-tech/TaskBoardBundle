<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_fill;

/**
 * Since 1.25 (§15.3): the status → column map — normalised matching, `__skip` / `__create`, the done column of
 * checklist items, and the validation of a posted map.
 */
final class StatusColumnsTest extends TestCase
{
    public function testStatusesMatchIgnoringCaseAccentsAndSpaces(): void
    {
        self::assertSame('enrevision', StatusColumns::normalize(' En Revisión '));
        self::assertSame('', StatusColumns::normalize(null));
        $map = [['status' => 'En revisión', 'column' => 'c1'], ['status' => 'New', 'column' => StatusColumns::CREATE]];
        self::assertSame('c1', StatusColumns::target($map, 'EN REVISION'));
        self::assertNull(StatusColumns::target($map, 'new'), '__create resolves by name later');
        self::assertNull(StatusColumns::target($map, ''));
        self::assertNull(StatusColumns::target($map, 'other'));
    }

    public function testMappedDoneColumnAndClean(): void
    {
        $board = new TaskBoard('B', 'b', new stdClass());
        $todo  = new BoardColumn($board, 'To do', 0);
        $done  = new BoardColumn($board, 'Shipped', 1);
        $board->addColumn($todo);
        $board->addColumn($done);

        self::assertTrue(StatusColumns::mapped([['status' => 'x', 'column' => StatusColumns::SKIP]], 'x', $board));
        self::assertTrue(StatusColumns::mapped([['status' => 'x', 'column' => $todo->getId()]], 'x', $board));
        self::assertFalse(StatusColumns::mapped([['status' => 'x', 'column' => 'gone']], 'x', $board));

        self::assertNull(StatusColumns::doneColumn([], $board));
        self::assertNull(StatusColumns::doneColumn([['status' => 'closed', 'column' => StatusColumns::SKIP, 'type' => 'closed'], ['status' => 'x', 'column' => 'gone', 'type' => 'done']], $board));
        self::assertSame($done, StatusColumns::doneColumn([['status' => 'Shipped', 'column' => StatusColumns::CREATE, 'type' => 'done']], $board));
        self::assertSame($todo, StatusColumns::doneColumn([['status' => 'a', 'column' => $done->getId(), 'type' => 'done'], ['status' => 'b', 'column' => $todo->getId(), 'type' => 'closed']], $board), 'closed before done');

        $map = StatusColumns::clean([
            ['status' => ' To do ', 'column' => $todo->getId(), 'type' => 'open'],
            ['status' => 'TO DO', 'column' => StatusColumns::SKIP],
            ['status' => 'Weird', 'column' => StatusColumns::SKIP, 'type' => 'nope'],
            ['status' => '', 'column' => StatusColumns::CREATE],
            ['status' => 'Gone', 'column' => 'unknown'],
            ['status' => 1, 'column' => 'x'],
            'junk',
        ], $board);
        self::assertSame([
            ['status' => 'To do', 'column' => $todo->getId(), 'type' => 'open'],
            ['status' => 'Weird', 'column' => StatusColumns::SKIP],
        ], $map);

        $many = [];
        for ($i = 0; $i < 210; ++$i) {
            $many[] = ['status' => 'S' . $i, 'column' => StatusColumns::SKIP];
        }
        self::assertCount(200, StatusColumns::clean($many, $board));
        self::assertCount(0, StatusColumns::clean(array_fill(0, 3, null), $board));
    }
}
