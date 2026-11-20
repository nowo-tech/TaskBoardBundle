<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search\Header;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Search\Header\TaskReference;
use Nowo\TaskBoardBundle\Search\Header\TaskSearchExtras;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/** Since 2.26.0: what a query names besides words, and the initials of assignees. */
final class TaskReferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, TaskReference|null}>
     */
    public static function queries(): iterable
    {
        yield 'hash number' => ['#123', new TaskReference(123, null, '#123')];
        yield 'bare number' => [' 42 ', new TaskReference(42, null, '42')];
        yield 'board key' => ['dev-7', new TaskReference(7, 'DEV', 'dev-7')];
        yield 'key with a dash in the prefix' => ['MY-APP-12', new TaskReference(12, 'MY-APP', 'MY-APP-12')];
        yield 'external id' => ['86b2xyz', new TaskReference(null, null, '86b2xyz')];
        yield 'zero' => ['#0', new TaskReference(null, null, '#0')];
        yield 'too many digits: an external id only' => ['99999999999', new TaskReference(null, null, '99999999999')];
        yield 'number past the integer column' => ['3000000000', new TaskReference(null, null, '3000000000')];
        yield 'prefix too long' => [str_repeat('A', 17) . '-1', new TaskReference(null, null, str_repeat('A', 17) . '-1')];
        yield 'two words' => ['dev 7', null];
        yield 'blank' => ['   ', null];
        yield 'external id too long' => [str_repeat('x', 129), null];
    }

    #[DataProvider('queries')]
    public function testParse(string $text, ?TaskReference $expected): void
    {
        self::assertEquals($expected, TaskReference::parse($text));
    }

    public function testNamesByNumber(): void
    {
        $board = new TaskBoard('Dev', 'dev', new stdClass());
        $board->setNumberPrefix('DEV');
        $task = new Task($board, 'T', new stdClass());
        $task->assignNumber(7);

        self::assertTrue((new TaskReference(7))->namesByNumber($task));
        self::assertTrue((new TaskReference(7, 'DEV'))->namesByNumber($task));
        self::assertFalse((new TaskReference(7, 'OPS'))->namesByNumber($task));
        self::assertFalse((new TaskReference(8))->namesByNumber($task));
        self::assertFalse((new TaskReference(null, null, 'x'))->namesByNumber($task));
    }

    public function testInitials(): void
    {
        self::assertSame('AL', TaskSearchExtras::initials('Ada Lovelace'));
        self::assertSame('AL', TaskSearchExtras::initials('ada.lovelace@example.com'));
        self::assertSame('G', TaskSearchExtras::initials('grace@example.com'));
        self::assertSame('ÉM', TaskSearchExtras::initials('élodie martin'));
        self::assertSame('?', TaskSearchExtras::initials('@example.com'));
        self::assertSame('#', TaskSearchExtras::initials('#5'));
    }
}
