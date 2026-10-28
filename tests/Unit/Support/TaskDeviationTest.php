<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Support\TaskTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 1.15: the estimate / spent deviation maths.
 */
final class TaskDeviationTest extends TestCase
{
    /** @return iterable<string, array{int|null, int, bool, string, int|null, int|null, int|null}> */
    public static function cases(): iterable
    {
        yield 'no estimate' => [null, 30, false, TaskDeviation::STATUS_NONE, null, null, null];
        yield 'zero estimate' => [0, 30, true, TaskDeviation::STATUS_NONE, null, null, null];
        yield 'open, below the estimate' => [120, 30, false, TaskDeviation::STATUS_ON_TRACK, -90, -75, 25];
        yield 'exactly the estimate' => [60, 60, true, TaskDeviation::STATUS_ON_TRACK, 0, 0, 100];
        yield 'over' => [60, 90, false, TaskDeviation::STATUS_OVER, 30, 50, 150];
        yield 'completed well under' => [100, 50, true, TaskDeviation::STATUS_UNDER, -50, -50, 50];
        yield 'completed just under' => [100, 95, true, TaskDeviation::STATUS_ON_TRACK, -5, -5, 95];
    }

    #[DataProvider('cases')]
    public function testDeviation(?int $estimate, int $spent, bool $completed, string $status, ?int $difference, ?int $percent, ?int $spentPercent): void
    {
        $deviation = new TaskDeviation($estimate, $spent, $completed);

        self::assertSame($status, $deviation->status());
        self::assertSame($difference, $deviation->difference());
        self::assertSame($percent, $deviation->percent());
        self::assertSame($spentPercent, $deviation->spentPercent());
        self::assertSame($status === TaskDeviation::STATUS_OVER, $deviation->isOver());
    }

    public function testOfATaskAndTheSumOfItsSubtasks(): void
    {
        $user   = new stdClass();
        $board  = new TaskBoard('Board', 'board', $user);
        $parent = new Task($board, 'Parent', $user, estimatedMinutes: 120);
        $child  = new Task($board, 'Child', $user, parent: $parent, estimatedMinutes: 60);
        $nested = new Task($board, 'Nested', $user, parent: $child);
        $parent->addTimeEntry(new TaskTimeEntry($parent, $user, 45, new DateTimeImmutable()));
        $child->addTimeEntry(new TaskTimeEntry($child, $user, 50, new DateTimeImmutable()));
        $nested->addTimeSeconds(1200);
        $nested->markCompleted();

        $own = TaskDeviation::of($parent);
        self::assertSame([120, 45, false], [$own->estimatedMinutes, $own->spentMinutes, $own->completed]);

        $descendants = TaskTree::fromTasks([$parent, $child, $nested])->descendantsOf($parent);
        self::assertSame([$child, $nested], $descendants);
        $sum = TaskDeviation::sum($descendants);
        self::assertSame([60, 70, false], [$sum->estimatedMinutes, $sum->spentMinutes, $sum->completed]);
        self::assertSame(TaskDeviation::STATUS_OVER, $sum->status());

        $none = TaskDeviation::sum([]);
        self::assertSame([null, 0, false], [$none->estimatedMinutes, $none->spentMinutes, $none->completed]);
        self::assertTrue(TaskDeviation::sum([$nested])->completed);
    }

    public function testFormatsMinutes(): void
    {
        self::assertSame('—', TaskDeviation::formatMinutes(null));
        self::assertSame('0 min', TaskDeviation::formatMinutes(0, true));
        self::assertSame('45 min', TaskDeviation::formatMinutes(45));
        self::assertSame('2 h', TaskDeviation::formatMinutes(120));
        self::assertSame('1 h 30 min', TaskDeviation::formatMinutes(90));
        self::assertSame('+20 min', TaskDeviation::formatMinutes(20, true));
        self::assertSame('−1 h 5 min', TaskDeviation::formatMinutes(-65, true));
    }
}
