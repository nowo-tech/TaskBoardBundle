<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\MultiBoard;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardExportRows;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScope;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskFilter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskRow;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;
use Nowo\TaskBoardBundle\MultiBoard\NullMultiBoardScopeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/**
 * Since 2.13.0: the value objects of the multi-board port (REQ-ARCH-001 rule 3): validated plain values only.
 */
#[CoversClass(MultiBoardViewer::class)]
#[CoversClass(MultiBoardScope::class)]
#[CoversClass(MultiBoardScopeEntry::class)]
#[CoversClass(MultiBoardTaskFilter::class)]
#[CoversClass(MultiBoardTaskRow::class)]
#[CoversClass(MultiBoardExportRows::class)]
#[CoversClass(NullMultiBoardScopeProvider::class)]
final class MultiBoardValueObjectsTest extends TestCase
{
    public function testTheViewer(): void
    {
        $viewer = new MultiBoardViewer('42', ['organisation' => 'acme']);

        self::assertSame('acme', $viewer->context('organisation'));
        self::assertNull($viewer->context('project'));
        self::assertSame(MultiBoardScope::none()->entries, (new NullMultiBoardScopeProvider())->readableBoards($viewer)->entries);
    }

    /**
     * @return iterable<string, array{string, array<mixed>}>
     */
    public static function invalidViewers(): iterable
    {
        yield 'empty id' => ['', []];
        yield 'long id' => [str_repeat('a', 129), []];
        yield 'control character' => ["4\n2", []];
        yield 'list context' => ['42', ['acme']];
        yield 'empty key' => ['42', ['' => 'acme']];
        yield 'int value' => ['42', ['organisation' => 1]];
        yield 'long value' => ['42', ['organisation' => str_repeat('a', 256)]];
        yield 'long key' => ['42', [str_repeat('k', 256) => 'acme']];
    }

    /**
     * @param array<mixed> $context
     */
    #[DataProvider('invalidViewers')]
    public function testAnInvalidViewerIsRefused(string $id, array $context): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultiBoardViewer($id, $context); // @phpstan-ignore argument.type
    }

    public function testTheScope(): void
    {
        $scope = new MultiBoardScope([new MultiBoardScopeEntry('b1', '  Project  ', 'p1'), new MultiBoardScopeEntry('b2', '   '), new MultiBoardScopeEntry('b1', 'Twice')]);

        self::assertSame(['b1', 'b2'], $scope->boardIds());
        self::assertSame('Project', $scope->entries[0]->label);
        self::assertSame('p1', $scope->entries[0]->tenantSlug);
        self::assertNull($scope->entries[1]->label);
        self::assertFalse($scope->isEmpty());
        self::assertTrue(MultiBoardScope::none()->isEmpty());
        self::assertSame(['x', 'y'], MultiBoardScope::ofBoardIds('x', 'y', 'x')->boardIds());
    }

    public function testAScopeOfSomethingElseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass given');

        new MultiBoardScope([new stdClass()]); // @phpstan-ignore argument.type
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'empty board' => ['', null, null];
        yield 'long board' => [str_repeat('b', 65), null, null];
        yield 'long label' => ['b', str_repeat('l', 256), null];
        yield 'empty slug' => ['b', null, ''];
        yield 'long slug' => ['b', null, str_repeat('s', 192)];
    }

    #[DataProvider('invalidEntries')]
    public function testAnInvalidEntryIsRefused(string $board, ?string $label, ?string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultiBoardScopeEntry($board, $label, $slug);
    }

    public function testTheFilterFromAQuery(): void
    {
        $filter = MultiBoardTaskFilter::fromQuery([
            'board'    => ' b1 ',
            'assignee' => '7',
            'column'   => 'Doing',
            'status'   => 'all',
            'priority' => 'urgent',
            'due_from' => '2030-01-01',
            'due_to'   => '2030-01-31',
            'overdue'  => 'on',
            'label'    => str_repeat('l', 150),
            'q'        => 'login',
            'sort'     => 'priority',
        ]);

        self::assertSame(['b1', '7', 'Doing', 'all', TaskPriority::Urgent, '2030-01-01', '2030-01-31', true, 100, 'login', 'priority', 'desc'], [$filter->boardId, $filter->assigneeId, $filter->column, $filter->status, $filter->priority, $filter->dueFrom?->format('Y-m-d'), $filter->dueTo?->format('Y-m-d'), $filter->overdue, mb_strlen((string) $filter->label), $filter->search, $filter->sort, $filter->direction]);
        self::assertSame(['board' => 'b1', 'assignee' => '7', 'column' => 'Doing', 'status' => 'all', 'priority' => 'urgent', 'due_from' => '2030-01-01', 'due_to' => '2030-01-31', 'overdue' => '1', 'label' => str_repeat('l', 100), 'q' => 'login'], $filter->toQuery());
        self::assertTrue($filter->isActive());
        self::assertNull($filter->withoutBoard()->boardId);
        self::assertNull($filter->withoutAssignee()->assigneeId);
        self::assertSame('b1', $filter->withoutAssignee()->boardId);
        self::assertSame('7', $filter->withoutBoard()->assigneeId);
    }

    public function testAnythingInvalidFallsBackToTheDefaults(): void
    {
        $filter = MultiBoardTaskFilter::fromQuery([
            'board'    => ['b1'],
            'status'   => 'deleted',
            'priority' => 'meh',
            'due_from' => '2031-02-30',
            'due_to'   => '30/01/2030',
            'overdue'  => 'yes',
            'q'        => '   ',
            'sort'     => 't.id; DROP TABLE',
            'dir'      => 'up',
        ]);

        self::assertEquals(new MultiBoardTaskFilter(), $filter);
        self::assertSame([], $filter->toQuery());
        self::assertFalse($filter->isActive());
        self::assertSame('asc', MultiBoardTaskFilter::fromQuery(['sort' => 'title', 'dir' => 'asc'])->direction);
        self::assertSame('desc', MultiBoardTaskFilter::fromQuery(['sort' => 'title', 'dir' => 'desc'])->direction);
    }

    public function testTheRowAndTheExportRows(): void
    {
        $row = new MultiBoardTaskRow('t1', null, 'Task', new MultiBoardBoard('b1', 'Board', 'Board'), null, TaskPriority::Normal, null, null, new DateTimeImmutable(), null, 0, [], [], null, false);
        self::assertTrue($row->isCompleted());

        $rows = new MultiBoardExportRows(static function () use ($row): Generator {
            yield $row;
        }, 3, 2);
        self::assertTrue($rows->isTruncated());
        self::assertSame([$row], iterator_to_array($rows->rows(), false));
        self::assertFalse((new MultiBoardExportRows(static fn (): Generator => yield from [], 2, 2))->isTruncated());
    }
}
